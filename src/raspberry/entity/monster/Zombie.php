<?php

declare(strict_types=1);

namespace raspberry\entity\monster;

use raspberry\entity\EntityFactory;
use raspberry\entity\EntityIds;
use raspberry\entity\Living;
use raspberry\entity\ai\Behavior;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use raspberry\nbt\tag\CompoundTag;

use raspberry\network\mcpe\protocol\types\EntityMetadata;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use function mt_getrandmax;
use function mt_rand;

class Zombie extends Monster{

    public const float FOLLOW_RANGE = 35.0;

    private const array RARE_DROPS = [ItemIds::IRON_INGOT, ItemIds::CARROT, ItemIds::POTATO];

    private const int CONVERSION_DELAY = 600;

    private const int SHAKING_TICKS = 300;

    private int $underwaterTicks = 0;

    private int $conversionTicks = -1;

    public function getNetworkTypeId() : string{
        return EntityIds::ZOMBIE;
    }

    protected function getBaseWidth() : float{
        return 0.6;
    }

    protected function getBaseHeight() : float{
        return 1.95;
    }

    protected function getDefaultMaxHealth() : float{
        return 20.0;
    }

    protected function getDefaultMovementSpeed() : float{
        return $this->baby ? 0.345 : 0.23;
    }

    protected function getAttackDamage() : float{
        return 3.0;
    }

    public function isUndead() : bool{
        return true;
    }

    public function canBreatheUnderwater() : bool{
        return true;
    }

    protected function burnsInDaylight() : bool{
        return true;
    }

    protected function getArmorValues() : array{
        return [2, 0.0];
    }

    public function setBaby(bool $baby) : void{
        parent::setBaby($baby);
        $this->updateEffectAttributes();
    }

    public function onSpawnedNaturally() : void{
        if(mt_rand(0, 99) < 5){
            $this->setBaby(true);
        }
    }

    public function getXpReward() : int{
        return $this->baby ? 12 : 5;
    }

    protected function getConversionType() : ?string{
        return EntityIds::DROWNED;
    }

    protected function getConversionSound() : string{
        return LevelSoundEvent::CONVERT_TO_DROWNED;
    }

    public function isConverting() : bool{
        return $this->conversionTicks >= 0;
    }

    public function createMetadata() : EntityMetadata{
        $metadata = parent::createMetadata();
        $metadata->setFlag(EntityMetadata::FLAG_SHAKING, $this->isConverting());
        return $metadata;
    }

    public function tick(int $currentTick) : void{
        parent::tick($currentTick);

        if($this->closed || !$this->isAlive() || $this->getConversionType() === null){
            return;
        }

        if($this->isConverting()){
            if(--$this->conversionTicks <= 0){
                $this->convert();
            }

            return;
        }

        if(!$this->isHeadInWater()){
            $this->underwaterTicks = 0;
        }elseif(++$this->underwaterTicks >= self::CONVERSION_DELAY){
            $this->conversionTicks = self::SHAKING_TICKS;
            $this->sendMetadata();
        }
    }

    private function convert() : void{
        $type = $this->getConversionType();

        if($type === null){
            return;
        }

        $mob = EntityFactory::create($type, $this->world, $this->position, $this->yaw);

        if($mob === null){
            return;
        }

        if($mob instanceof Drowned){
            $mob->clearEquipment();
        }

        $mob->setBaby($this->baby);

        if($mob instanceof Monster){
            $mob->setPersistent($this->isPersistent());
        }

        $this->playSound($this->getConversionSound());
        $this->close();
    }

    public function getBehaviors() : array{
        return [
            Behavior::hurtByTarget(1, self::FOLLOW_RANGE),
            Behavior::meleeAttack(2, 1.0),
            Behavior::nearestAttackableTarget(2, self::FOLLOW_RANGE),
            Behavior::randomStroll(7, 1.0),
            Behavior::lookAtPlayer(8, 8.0),
            Behavior::randomLookAround(8)
        ];
    }

    /**
     * A burning zombie sets what it hits on fire, more often in harder worlds.
     */
    protected function onHurtTarget(Living $target) : void{
        $difficulty = self::getEffectiveDifficulty();

        if($this->isOnFire() && mt_rand() / mt_getrandmax() < $difficulty * 0.3){
            $target->setOnFire(2 * (int) $difficulty);
        }
    }

    protected function getDrops(int $looting) : array{
        $drops = [];
        $flesh = self::lootCount(0, 2, $looting);

        if($flesh > 0){
            $drops[] = new Item(ItemIds::ROTTEN_FLESH, $flesh);
        }

        if($this->getKiller() !== null && mt_rand() / mt_getrandmax() < 0.025 + 0.01 * $looting){
            $drops[] = new Item(self::RARE_DROPS[mt_rand(0, 2)]);
        }

        return $drops;
    }

    public function save() : ?CompoundTag{
        $tag = parent::save();
        $tag?->setInt("InWaterTime", $this->underwaterTicks);
        $tag?->setInt("DrownedConversionTime", $this->conversionTicks);
        return $tag;
    }

    public function load(CompoundTag $tag) : void{
        parent::load($tag);
        $this->underwaterTicks = $tag->getInt("InWaterTime", 0);
        $this->conversionTicks = $tag->getInt("DrownedConversionTime", -1);
    }
}