<?php

declare(strict_types=1);

namespace raspberry\entity\animal;

use raspberry\entity\ai\Behavior;
use raspberry\entity\EntityIds;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use raspberry\math\Vector3;

use raspberry\nbt\tag\CompoundTag;

use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\world\World;

use function mt_rand;

class Chicken extends Animal{
    use ClimateVariantTrait;

    /** a hen lays an egg every 5 to 10 minutes */
    private const MIN_EGG_TICKS = 6000;
    private const MAX_EGG_TICKS = 12000;

    private int $eggTicks;

    public function __construct(World $world, Vector3 $position, float $yaw = 0.0){
        parent::__construct($world, $position, $yaw);
        $this->pickVariant();
        $this->eggTicks = mt_rand(self::MIN_EGG_TICKS, self::MAX_EGG_TICKS);
    }

    public function getNetworkTypeId() : string{
        return EntityIds::CHICKEN;
    }

    protected function getBaseWidth() : float{
        return 0.6;
    }

    protected function getBaseHeight() : float{
        return 0.8;
    }

    protected function getDefaultMaxHealth() : float{
        return 4.0;
    }

    protected function getDefaultMovementSpeed() : float{
        return 0.25;
    }

    /** flaps its way down */
    protected function getAiFlags() : int{
        return self::AI_SLOW_FALL;
    }

    protected function takesFallDamage() : bool{
        return false;
    }

    public function getBreedItems() : array{
        return [ItemIds::WHEAT_SEEDS, ItemIds::BEETROOT_SEEDS, ItemIds::MELON_SEEDS, ItemIds::PUMPKIN_SEEDS, ItemIds::PITCHER_POD, ItemIds::TORCHFLOWER_SEEDS];
    }

    public function getBehaviors() : array{
        return [
            Behavior::float(0),
            Behavior::panic(1, 1.5),
            Behavior::breed(3, 1.0),
            Behavior::tempt(4, 1.0, $this->getBreedItems()),
            Behavior::followParent(5, 1.1),
            Behavior::randomStroll(6, 1.0),
            Behavior::lookAtPlayer(7),
            Behavior::randomLookAround(8)
        ];
    }

    protected function getDrops(int $looting) : array{
        return [
            new Item(ItemIds::FEATHER, self::lootCount(0, 2, $looting)),
            new Item($this->isOnFire() ? ItemIds::COOKED_CHICKEN : ItemIds::CHICKEN, 1)
        ];
    }

    public function tick(int $currentTick) : void{
        parent::tick($currentTick);

        if($this->closed || !$this->isAlive() || $this->baby || --$this->eggTicks > 0){
            return;
        }

        $this->eggTicks = mt_rand(self::MIN_EGG_TICKS, self::MAX_EGG_TICKS);
        $this->playSound(LevelSoundEvent::PLOP);
        $this->world->dropItem($this->position, new Item(ItemIds::EGG), Vector3::zero());
    }

    public function save() : ?CompoundTag{
        $tag = $this->saveVariant(parent::save());
        $tag?->setInt("EggLayTime", $this->eggTicks);
        return $tag;
    }

    public function load(CompoundTag $tag) : void{
        parent::load($tag);
        $this->loadVariant($tag);
        $this->eggTicks = $tag->getInt("EggLayTime", $this->eggTicks);
    }
}