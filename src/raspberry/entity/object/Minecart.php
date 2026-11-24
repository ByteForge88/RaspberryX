<?php

declare(strict_types=1);

namespace raspberry\entity\object;

use raspberry\entity\EntityIds;

use raspberry\item\ItemIds;

use raspberry\math\Vector3;

use raspberry\player\Player;

use function cos;
use function deg2rad;
use function min;
use function sin;
use function sqrt;

class Minecart extends Cart{

    private const float WALK_PUSH = 0.1;

    private ?Vector3 $riderPush = null;

    public function getNetworkTypeId() : string{
        return EntityIds::MINECART;
    }

    public function getItemId() : string{
        return ItemIds::MINECART;
    }

    public function canBoard(Player $player) : bool{
        return $this->passengers === [] && $player->getVehicle() === null && !$this->closed && $player->getWorld() === $this->world;
    }

    public function getInteractText(Player $player) : string{
        return $this->canBoard($player) ? "action.interact.ride.minecart" : "";
    }

    public function interact(Player $player) : void{
        if(!$player->isSneaking()){
            $this->addPassenger($player);
        }
    }

    public function steer(Player $rider, float $yaw, float $strafe, float $forward) : void{
        if($this->getDriver() !== $rider){
            return;
        }

        $length = sqrt($strafe * $strafe + $forward * $forward);

        if($length < 0.01){
            $this->riderPush = null;
            return;
        }

        $scale = min(1.0, 1.0 / $length) * self::WALK_PUSH;
        [$strafe, $forward] = [$strafe * $scale, $forward * $scale];
        $angle = deg2rad($yaw);
        $this->riderPush = new Vector3($strafe * cos($angle) - $forward * sin($angle), 0.0, $forward * cos($angle) + $strafe * sin($angle));
    }

    public function removePassenger(Player $player, ?Vector3 $at = null, bool $immediate = false) : void{
        parent::removePassenger($player, $at, $immediate);

        if($this->passengers === []){
            $this->riderPush = null;
        }
    }

    protected function getRiderPush() : ?Vector3{
        return $this->riderPush;
    }

    protected function onActivatorRail(bool $powered) : void{
        if($powered){
            $this->removePassengers();
        }
    }
}