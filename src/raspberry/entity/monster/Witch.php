<?php

declare(strict_types=1);

namespace raspberry\entity\monster;

use raspberry\entity\EntityIds;
use raspberry\entity\Human;
use raspberry\entity\Living;
use raspberry\entity\ai\Behavior;
use raspberry\entity\effect\Effect;
use raspberry\entity\object\ThrownPotion;
use raspberry\entity\projectile\Projectile;

use raspberry\item\Item;
use raspberry\item\ItemIds;
use raspberry\item\Potion;

use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\EntityMetadata;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use function count;
use function mt_getrandmax;
use function mt_rand;
use function sqrt;

class Witch extends Monster{

    public const float FOLLOW_RANGE = 64.0;
    public const float TARGET_RANGE = 10.0;

    private const int WATER_BREATHING = 19;
    private const int FIRE_RESISTANCE = 12;
    private const int HEALING = 21;
    private const int SWIFTNESS = 14;
    private const int SLOWNESS = 17;
    private const int HARMING = 23;
    private const int POISON = 25;
    private const int WEAKNESS = 34;

    private const float WATER_BREATHING_CHANCE = 0.15;
    private const float FIRE_RESISTANCE_CHANCE = 0.15;
    private const float HEALING_CHANCE = 0.05;
    private const float SWIFTNESS_CHANCE = 0.25;
    private const float SWIFTNESS_DISTANCE = 11.0;

    private const int DRINK_TICKS = 32;

    private const int THROW_TICKS = 60;
    private const float THROW_RADIUS = 10.0;
    private const float THROW_SPEED = 0.75;
    private const float THROW_SPREAD = 8.0;

    private const float SLOWNESS_DISTANCE = 8.0;
    private const float POISON_HEALTH = 8.0;
    private const float WEAKNESS_DISTANCE = 3.0;
    private const float WEAKNESS_CHANCE = 0.25;

    private const float HELD_DROP_CHANCE = 0.085;

    private const array LOOT = [
        ItemIds::GLOWSTONE_DUST, ItemIds::SUGAR, ItemIds::SPIDER_EYE, ItemIds::GLASS_BOTTLE, ItemIds::GUNPOWDER, ItemIds::STICK, ItemIds::STICK
    ];

    private ?Item $potion = null;

    private int $drinkTicks = 0;

    public function getNetworkTypeId() : string{
        return EntityIds::WITCH;
    }

    protected function getBaseWidth() : float{
        return 0.6;
    }

    protected function getBaseHeight() : float{
        return 1.9;
    }

    protected function getDefaultMaxHealth() : float{
        return 26.0;
    }

    protected function getDefaultMovementSpeed() : float{
        return $this->isDrinking() ? 0.0 : 0.25;
    }

    protected function getAttackDamage() : float{
        return 0.0;
    }

    public function getBehaviors() : array{
        return [
            Behavior::float(1),
            Behavior::hurtByTarget(1, self::FOLLOW_RANGE),
            Behavior::nearestAttackableTarget(2, self::TARGET_RANGE),
            Behavior::rangedAttack(2, 1.0, self::THROW_TICKS, self::THROW_TICKS, self::THROW_RADIUS),
            Behavior::randomStroll(4, 1.0),
            Behavior::lookAtPlayer(5, 16.0),
            Behavior::randomLookAround(5)
        ];
    }

    public function isDrinking() : bool{
        return $this->drinkTicks > 0;
    }

    public function getHeldItem() : ?Item{
        return $this->potion;
    }

    public function getXpReward() : int{
        return parent::getXpReward() + ($this->potion !== null ? mt_rand(1, 3) : 0);
    }

    public function createMetadata() : EntityMetadata{
        $metadata = parent::createMetadata();
        $metadata->setFlag(EntityMetadata::FLAG_USING_ITEM, $this->isDrinking());
        return $metadata;
    }

    public function tick(int $currentTick) : void{
        parent::tick($currentTick);

        if($this->closed || !$this->isAlive()){
            return;
        }

        if($this->isDrinking()){
            if(--$this->drinkTicks <= 0){
                $this->finishDrinking();
            }

            return;
        }

        $potion = $this->choosePotion();

        if($potion !== null){
            $this->startDrinking($potion);
        }
    }

    private function choosePotion() : ?int{
        $effects = $this->effects;

        if(self::roll(self::WATER_BREATHING_CHANCE) && $this->isHeadInWater() && !$effects->has(Effect::WATER_BREATHING)){
            return self::WATER_BREATHING;
        }

        if(self::roll(self::FIRE_RESISTANCE_CHANCE) && ($this->isOnFire() || ($this->invulnerableTicks > 0 && $this->getLastDamageSource()?->isFire() === true)) && !$effects->has(Effect::FIRE_RESISTANCE)){
            return self::FIRE_RESISTANCE;
        }

        if(self::roll(self::HEALING_CHANCE) && $this->getHealth() < $this->getMaxHealth()){
            return self::HEALING;
        }

        $target = $this->getTarget();

        if(self::roll(self::SWIFTNESS_CHANCE) && $target !== null && !$effects->has(Effect::SPEED) && $target->getPosition()->distanceSquared($this->position) >= self::SWIFTNESS_DISTANCE ** 2){
            return self::SWIFTNESS;
        }

        return null;
    }

    private static function roll(float $chance) : bool{
        return mt_rand() / mt_getrandmax() < $chance;
    }

    private function startDrinking(int $potion) : void{
        $this->potion = new Item(ItemIds::POTION, 1, $potion);
        $this->drinkTicks = self::DRINK_TICKS;
        $this->changeHeldItem();

        $packet = LevelSoundEventPacket::create(LevelSoundEvent::DRINK, $this->position->x, $this->position->y + $this->getEyeHeight(), $this->position->z);
        $packet->entityType = $this->getNetworkTypeId();
        $this->world->broadcastPacket($this->position->getFloorX(), $this->position->getFloorZ(), $packet);
    }

    private function finishDrinking() : void{
        $potion = $this->potion;
        $this->potion = null;
        $this->changeHeldItem();

        if($potion === null){
            return;
        }

        foreach(Potion::getEffects($potion->getMeta()) as $effect){
            $this->effects->add($effect);
        }
    }

    private function changeHeldItem() : void{
        $this->updateEffectAttributes();
        $this->sendMetadata();

        foreach($this->getViewers() as $viewer){
            $viewer->getNetworkSession()->sendMobEquipment($this);
        }
    }

    public function onEngineEvent(int $kind, float $value, int $x, int $y, int $z, int $other) : void{
        if($kind === self::EVENT_SHOOT){
            $target = $this->world->getEntity($other);

            if($target instanceof Living && !$target->isClosed() && !$this->isDrinking()){
                $this->throwAt($target);
            }

            return;
        }

        if($kind === self::EVENT_USE_ITEM){
            return;
        }

        parent::onEngineEvent($kind, $value, $x, $y, $z, $other);
    }

    private function throwAt(Living $target) : void{
        $at = $target->getPosition();
        $eye = $at->y + ($target instanceof Human ? Human::EYE_HEIGHT : $target->getEyeHeight());
        $from = new Vector3($this->position->x, $this->position->y + $this->getEyeHeight() - 0.1, $this->position->z);

        $dx = $at->x - $this->position->x;
        $dy = $eye - 1.1 - $this->position->y;
        $dz = $at->z - $this->position->z;
        $flat = sqrt($dx * $dx + $dz * $dz);

        $item = new Item(ItemIds::SPLASH_POTION, 1, $this->choosePotionFor($target, $flat));
        new ThrownPotion($this->world, $from, $item, Projectile::aim($dx, $dy + $flat * 0.2, $dz, self::THROW_SPEED, self::THROW_SPREAD), $this);

        $packet = LevelSoundEventPacket::create(LevelSoundEvent::THROW, $from->x, $from->y, $from->z);
        $packet->entityType = $this->getNetworkTypeId();
        $this->world->broadcastPacket($from->getFloorX(), $from->getFloorZ(), $packet);
    }

    private function choosePotionFor(Living $target, float $distance) : int{
        $effects = $target->getEffects();

        if($distance >= self::SLOWNESS_DISTANCE && !$effects->has(Effect::SLOWNESS)){
            return self::SLOWNESS;
        }

        if($target->getHealth() >= self::POISON_HEALTH && !$effects->has(Effect::POISON)){
            return self::POISON;
        }

        if($distance <= self::WEAKNESS_DISTANCE && !$effects->has(Effect::WEAKNESS) && self::roll(self::WEAKNESS_CHANCE)){
            return self::WEAKNESS;
        }

        return self::HARMING;
    }

    protected function getDrops(int $looting) : array{
        $drops = [];

        for($rolls = mt_rand(1, 3); $rolls > 0; --$rolls){
            $count = self::lootCount(0, 2, $looting);

            if($count > 0){
                $drops[] = new Item(self::LOOT[mt_rand(0, count(self::LOOT) - 1)], $count);
            }
        }

        $drops[] = new Item(ItemIds::REDSTONE, self::lootCount(4, 8, $looting));

        if($this->potion !== null && $this->getKiller() !== null && self::roll(self::HELD_DROP_CHANCE + 0.01 * $looting)){
            $drops[] = clone $this->potion;
        }

        return $drops;
    }
}