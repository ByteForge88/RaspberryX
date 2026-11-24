<?php

declare(strict_types=1);

namespace raspberry\entity;

use raspberry\block\BlockCollision;

use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\SetActorDataPacket;
use raspberry\network\mcpe\protocol\SetActorLinkPacket;
use raspberry\network\mcpe\protocol\types\EntityMetadata;

use raspberry\player\Player;

use raspberry\world\World;

use function array_search;
use function array_values;
use function cos;
use function count;
use function deg2rad;
use function floor;
use function max;
use function sin;

abstract class Vehicle extends Entity{

    public const float PLAYER_SEAT_LIFT = 1.22001;

    public const float BREAK_DAMAGE = 40.0;

    public const int HURT_TICKS = 10;

    /** @var list<Player> */
    protected array $passengers = [];

    protected float $damage = 0.0;

    protected int $hurtTime = 0;

    protected int $hurtDirection = 1;

    abstract public function createMetadata() : EntityMetadata;

    abstract public function getMotion() : Vector3;

    abstract public function getSeat(Player $rider) : Vector3;

    abstract public function destroy(bool $drop) : void;

    abstract public function getInteractText(Player $player) : string;

    abstract public function interact(Player $player) : void;

    public function getMaxPassengers() : int{
        return 1;
    }

    public function getRiderRotationLock() : ?float{
        return null;
    }

    public function getRiderRotationOffset() : float{
        return 0.0;
    }

    /**
     * @return list<Player>
     */
    public function getPassengers() : array{
        return $this->passengers;
    }

    public function getDriver() : ?Player{
        return $this->passengers[0] ?? null;
    }

    public function canBoard(Player $player) : bool{
        return !$this->closed && count($this->passengers) < $this->getMaxPassengers() && $player->getVehicle() === null && $player->getWorld() === $this->world;
    }

    public function addPassenger(Player $player) : bool{
        if(!$this->canBoard($player)){
            return false;
        }

        $this->passengers[] = $player;
        $player->setVehicle($this);
        $this->sendLink($player, count($this->passengers) === 1 ? SetActorLinkPacket::TYPE_RIDER : SetActorLinkPacket::TYPE_PASSENGER);
        $this->updatePassengers();
        return true;
    }

    public function removePassenger(Player $player, ?Vector3 $at = null, bool $immediate = false) : void{
        $index = array_search($player, $this->passengers, true);

        if($index === false){
            return;
        }

        unset($this->passengers[$index]);
        $this->passengers = array_values($this->passengers);
        $player->setVehicle(null);
        $this->sendLink($player, SetActorLinkPacket::TYPE_REMOVE, $immediate);

        foreach($this->passengers as $seat => $rider){
            $this->sendLink($rider, $seat === 0 ? SetActorLinkPacket::TYPE_RIDER : SetActorLinkPacket::TYPE_PASSENGER);
        }

        $this->updatePassengers();

        if(!$player->isClosed() && $player->isAlive()){
            $player->teleport($this->findDismountSpot($at));
        }
    }

    public function removePassengers(bool $immediate = false) : void{
        foreach($this->passengers as $passenger){
            $this->removePassenger($passenger, null, $immediate);
        }
    }

    public function sendLinksTo(Player $viewer) : void{
        foreach($this->passengers as $seat => $rider){
            if($rider === $viewer || isset($rider->getViewers()[$viewer->getId()])){
                $viewer->getNetworkSession()->sendDataPacket(
                    SetActorLinkPacket::create(
                        $this->id,
                        $rider->getId(),
                        $seat === 0 ? SetActorLinkPacket::TYPE_RIDER : SetActorLinkPacket::TYPE_PASSENGER,
                        false,
                        true
                    )
                );
            }
        }
    }

    public function hurt(float $amount, ?Player $attacker = null) : void{
        if($this->closed){
            return;
        }

        $this->hurtDirection = -$this->hurtDirection;
        $this->hurtTime = self::HURT_TICKS;
        $this->damage += $amount * 10;
        $creative = $attacker !== null && $attacker->getGameMode()->hasInstantBuild();

        if($creative || $this->damage > self::BREAK_DAMAGE){
            $this->destroy(!$creative);
            return;
        }

        $this->broadcastMetadata();
    }

    public function hurtByExplosion(float $amount) : void{
        $this->hurt($amount);
    }

    public function close() : void{
        if(!$this->closed){
            $this->removePassengers(true);
        }

        parent::close();
    }

    protected function recover() : bool{
        $changed = false;

        if($this->hurtTime > 0){
            --$this->hurtTime;
            $changed = true;
        }

        if($this->damage > 0){
            $this->damage = max(0.0, $this->damage - 1);
            $changed = true;
        }

        return $changed;
    }

    protected function dropLostPassengers() : void{
        foreach($this->passengers as $passenger){
            if($passenger->isClosed() || !$passenger->isAlive() || $passenger->getWorld() !== $this->world){
                $this->removePassenger($passenger, null, true);
            }
        }
    }

    protected function updatePassengers() : void{
        $yaw = deg2rad($this->yaw);

        foreach($this->passengers as $passenger){
            $seat = $this->getSeat($passenger);
            $passenger->setPosition(new Vector3(
                $this->position->x + $seat->x * cos($yaw),
                $this->position->y + $seat->y + $this->getNetworkOffset(),
                $this->position->z + $seat->x * sin($yaw)
            ));
            $passenger->onVehicleMoved();
        }
    }

    protected function findDismountSpot(?Vector3 $at) : Vector3{
        if($at !== null && $at->distanceSquared($this->position) <= 9.0 && !self::spaceBlocked($this->world, $at)){
            return $at;
        }

        $yaw = deg2rad($this->yaw);

        foreach([1, -1] as $side){
            $spot = new Vector3($this->position->x - sin($yaw) * $side * 1.2, $this->position->y, $this->position->z + cos($yaw) * $side * 1.2);

            if(!self::spaceBlocked($this->world, $spot) && self::spaceBlocked($this->world, $spot->add(0, -1, 0))){
                return $spot;
            }
        }

        return $this->position->add(0, $this->getHeight(), 0);
    }

    protected function sendLink(Player $rider, int $type, bool $immediate = false) : void{
        $packet = SetActorLinkPacket::create($this->id, $rider->getId(), $type, $immediate, true);
        $rider->getNetworkSession()->sendDataPacket($packet);

        foreach($this->viewers as $viewer){
            if($viewer !== $rider && isset($rider->getViewers()[$viewer->getId()])){
                $viewer->getNetworkSession()->sendDataPacket($packet);
            }
        }
    }

    protected function broadcastMetadata(?Player $except = null) : void{
        $packet = SetActorDataPacket::create($this->id, $this->createMetadata(), 0);

        foreach($this->viewers as $viewer){
            if($viewer !== $except){
                $viewer->getNetworkSession()->sendDataPacket($packet);
            }
        }
    }

    protected static function spaceBlocked(World $world, Vector3 $at) : bool{
        $x = $at->getFloorX();
        $z = $at->getFloorZ();

        for($y = (int) floor($at->y); $y <= (int) floor($at->y + 1.8); ++$y){
            if(!$world->isChunkLoaded($x >> 4, $z >> 4) || BlockCollision::isSolid($world->getBlock($x, $y, $z))){
                return true;
            }
        }

        return false;
    }
}