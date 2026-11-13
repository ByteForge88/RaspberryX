<?php

declare(strict_types=1);

namespace raspberry\entity\animal;

use raspberry\entity\EntityIds;
use raspberry\entity\ai\Behavior;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use raspberry\math\Vector3;

use raspberry\nbt\tag\CompoundTag;

use raspberry\world\World;

class Pig extends Animal{
    use ClimateVariantTrait;

    public function __construct(World $world, Vector3 $position, float $yaw = 0.0){
        parent::__construct($world, $position, $yaw);
        $this->pickVariant();
    }

    public function getNetworkTypeId() : string{
        return EntityIds::PIG;
    }

    protected function getBaseWidth() : float{
        return 0.9;
    }

    protected function getBaseHeight() : float{
        return 0.9;
    }

    protected function getDefaultMaxHealth() : float{
        return 10.0;
    }

    protected function getDefaultMovementSpeed() : float{
        return 0.25;
    }

    protected function getAiFlags() : int{
        return self::AI_AVOID_WATER;
    }

    public function getBreedItems() : array{
        return [ItemIds::CARROT, ItemIds::BEETROOT, ItemIds::POTATO];
    }

    public function getBehaviors() : array{
        return [
            Behavior::float(2),
            Behavior::panic(3, 1.25),
            Behavior::breed(4, 1.0),
            Behavior::tempt(5, 1.2, [ItemIds::POTATO, ItemIds::CARROT, ItemIds::BEETROOT, ItemIds::CARROT_ON_A_STICK]),
            Behavior::followParent(6, 1.1),
            Behavior::randomStroll(7, 1.0),
            Behavior::lookAtPlayer(8),
            Behavior::randomLookAround(9)
        ];
    }

    protected function getDrops(int $looting) : array{
        return [new Item($this->isOnFire() ? ItemIds::COOKED_PORKCHOP : ItemIds::PORKCHOP, self::lootCount(1, 3, $looting))];
    }

    public function save() : ?CompoundTag{
        return $this->saveVariant(parent::save());
    }

    public function load(CompoundTag $tag) : void{
        parent::load($tag);
        $this->loadVariant($tag);
    }
}