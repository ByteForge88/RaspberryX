<?php

declare(strict_types=1);

namespace raspberry\entity\projectile;

use raspberry\entity\Human;
use raspberry\entity\Living;
use raspberry\entity\EntityIds;
use raspberry\entity\damage\DamageSource;

use raspberry\item\Item;
use raspberry\item\ItemIds;
use raspberry\item\Potion;

use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\SetActorMotionPacket;
use raspberry\network\mcpe\protocol\TakeItemActorPacket;
use raspberry\network\mcpe\protocol\types\EntityMetadata;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\player\GameMode;

use raspberry\world\World;

use function ceil;
use function intdiv;
use function max;
use function mt_rand;
use function mt_getrandmax;

class Arrow extends Projectile{

    public const int PICKUP_NONE = 0;
    public const int PICKUP_ANY = 1;
    public const int PICKUP_CREATIVE = 2;

    public const float BASE_DAMAGE = 2.0;

    /** an arrow stuck in a block goes after a minute */
    private const int GROUND_LIFE = 1200;

    /** a freshly stuck arrow cannot be picked up for this long */
    private const int SHAKE_TICKS = 7;

    private const int FIRE_SECONDS = 5;

    private float $baseDamage = self::BASE_DAMAGE;

    private bool $critical = false;

    private int $punch = 0;

    private bool $burning = false;

    private int $pickup;

    /** the potion of a tipped arrow, 0 for a plain one */
    private int $potion = 0;

    /** @var array{int, int, int}|null */
    private ?array $stuckIn = null;

    private int $groundTicks = 0;

    private int $shake = 0;

    public function __construct(World $world, Vector3 $position, Vector3 $motion, ?Living $owner, int $pickup = self::PICKUP_NONE){
        $this->pickup = $pickup;

        parent::__construct($world, $position, $motion, $owner);
    }

    public function getNetworkTypeId() : string{
        return EntityIds::ARROW;
    }

    public function getWidth() : float{
        return 0.5;
    }

    public function getHeight() : float{
        return 0.5;
    }

    protected function getGravity() : float{
        return 0.05;
    }

    protected function getDrag() : float{
        return $this->isInWater() ? 0.6 : 0.99;
    }

    public function getBaseDamage() : float{
        return $this->baseDamage;
    }

    public function setBaseDamage(float $damage) : void{
        $this->baseDamage = $damage;
    }

    public function isCritical() : bool{
        return $this->critical;
    }

    public function setCritical(bool $critical) : void{
        $this->critical = $critical;
    }

    public function setPunch(int $level) : void{
        $this->punch = $level;
    }

    public function setBurning(bool $burning) : void{
        $this->burning = $burning;
    }

    public function setPotion(int $potion) : void{
        $this->potion = $potion;
    }

    public function isStuck() : bool{
        return $this->stuckIn !== null;
    }

    public function createMetadata() : EntityMetadata{
        $metadata = parent::createMetadata();
        $metadata->setFlag(EntityMetadata::FLAG_CRITICAL, $this->critical);
        $metadata->setFlag(EntityMetadata::FLAG_ON_FIRE, $this->burning);

        return $metadata;
    }

    public function tick(int $currentTick) : void{
        if($this->closed || $this->stuckIn === null){
            parent::tick($currentTick);
            return;
        }

        if(++$this->groundTicks >= self::GROUND_LIFE){
            $this->close();
            return;
        }

        [$x, $y, $z] = $this->stuckIn;

        if(!$this->isSolidAt($x, $y, $z)){
            $this->stuckIn = null;
            $this->motion = new Vector3((mt_rand() / mt_getrandmax()) * 0.2, (mt_rand() / mt_getrandmax()) * 0.2, (mt_rand() / mt_getrandmax()) * 0.2);
            return;
        }

        if($this->shake > 0){
            --$this->shake;
            return;
        }

        $this->tryPickup();
    }

    protected function onHitEntity(Living $target, Vector3 $point) : void{
        $speed = $this->motion->length();
        $damage = (int) ceil(max(0.0, $speed * $this->baseDamage));

        if($this->critical){
            $damage += mt_rand(0, intdiv($damage, 2) + 1);
        }

        if($this->burning){
            $target->setOnFire(self::FIRE_SECONDS);
        }

        if(!$target->damage(new DamageSource(DamageSource::CAUSE_PROJECTILE, (float) $damage, $this->getOwner() ?? $this))){
            $this->setMotion(new Vector3($this->motion->x * -0.1, $this->motion->y * -0.1, $this->motion->z * -0.1));
            return;
        }

        if($target->wasLastHitFresh()){
            $target->knockBack($this->motion->x, $this->motion->z);

            if($this->punch > 0){
                $target->knockBack($this->motion->x, $this->motion->z, $this->punch * 0.6, 0.1);
            }
        }

        if($this->potion > 0){
            foreach(Potion::getEffects($this->potion, 8) as $effect){
                $target->getEffects()->add($effect);
            }
        }

        $this->playSound(LevelSoundEvent::BOW_HIT);
        $this->close();
    }

    protected function onHitBlock(int $x, int $y, int $z, Vector3 $point) : void{
        $length = $this->motion->length();
        $back = $length > 0.0 ? 0.05 / $length : 0.0;

        $this->stuckIn = [$x, $y, $z];
        $this->shake = self::SHAKE_TICKS;
        $this->critical = false;
        $this->move($point->add(-$this->motion->x * $back, -$this->motion->y * $back, -$this->motion->z * $back), $this->yaw, $this->pitch);
        $this->motion = new Vector3(0.0, 0.0, 0.0);

        $this->playSound(LevelSoundEvent::BOW_HIT);

        $packet = SetActorMotionPacket::create($this->id, 0.0, 0.0, 0.0, 0);

        foreach($this->viewers as $viewer){
            $viewer->getNetworkSession()->sendDataPacket($packet);
        }
    }

    private function tryPickup() : void{
        if($this->pickup === self::PICKUP_NONE){
            return;
        }

        foreach($this->world->getPlayers() as $player){
            if(!$player->isSpawned() || $player->isDead() || $player->getGameMode() === GameMode::SPECTATOR){
                continue;
            }

            $feet = $player->getPosition();
            $half = Human::WIDTH / 2 + 1;

            if($this->position->x < $feet->x - $half || $this->position->x > $feet->x + $half || $this->position->z < $feet->z - $half || $this->position->z > $feet->z + $half ||
                $this->position->y < $feet->y - 0.5 || $this->position->y > $feet->y + Human::HEIGHT + 0.5){
                continue;
            }

            if($this->pickup === self::PICKUP_ANY && !$player->getGameMode()->hasInstantBuild()){
                $arrow = new Item(ItemIds::ARROW, 1, $this->potion > 0 ? $this->potion + 1 : 0);

                if($player->getInventory()->addItem($arrow) !== []){
                    continue;
                }
            }elseif($this->pickup === self::PICKUP_CREATIVE && !$player->getGameMode()->hasInstantBuild()){
                continue;
            }

            $packet = TakeItemActorPacket::create($this->id, $player->getId());

            foreach($this->viewers + [$player->getId() => $player] as $viewer){
                $viewer->getNetworkSession()->sendDataPacket($packet);
            }

            $this->close();
            return;
        }
    }

    private function playSound(string $sound) : void{
        $this->world->broadcastPacket($this->position->getFloorX(), $this->position->getFloorZ(), LevelSoundEventPacket::create($sound, $this->position->x, $this->position->y, $this->position->z));
    }
}