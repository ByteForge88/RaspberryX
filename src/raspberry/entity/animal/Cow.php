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

use raspberry\player\Player;

use raspberry\world\World;

class Cow extends Animal{
    use ClimateVariantTrait;

    public function __construct(World $world, Vector3 $position, float $yaw = 0.0){
        parent::__construct($world, $position, $yaw);
        $this->pickVariant();
    }

    public function getNetworkTypeId() : string{
        return EntityIds::COW;
    }

    protected function getBaseWidth() : float{
        return 0.9;
    }

    protected function getBaseHeight() : float{
        return 1.3;
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
        return [ItemIds::WHEAT];
    }

    public function getBehaviors() : array{
        return [
            Behavior::float(0),
            Behavior::panic(1, 1.25),
            Behavior::breed(3, 1.0),
            Behavior::tempt(4, 1.25, [ItemIds::WHEAT]),
            Behavior::followParent(5, 1.1),
            Behavior::randomStroll(6, 0.8),
            Behavior::lookAtPlayer(7),
            Behavior::randomLookAround(9)
        ];
    }

    protected function getDrops(int $looting) : array{
        return [
            new Item(ItemIds::LEATHER, self::lootCount(0, 2, $looting)),
            new Item($this->isOnFire() ? ItemIds::COOKED_BEEF : ItemIds::BEEF, self::lootCount(1, 3, $looting))
        ];
    }

    private function canBeMilkedBy(Player $player) : bool{
        return !$this->baby && $player->getInventory()->getItemInHand()->getId() === ItemIds::BUCKET;
    }

    public function getInteractText(Player $player) : string{
        return $this->canBeMilkedBy($player) ? "action.interact.milk" : parent::getInteractText($player);
    }

    public function onInteract(Player $player) : bool{
        if(!$this->canBeMilkedBy($player)){
            return parent::onInteract($player);
        }

        $this->playSound(LevelSoundEvent::MILK);

        if(!$player->getGameMode()->hasInstantBuild()){
            self::useUpHeld($player);
            self::give($player, new Item(ItemIds::MILK_BUCKET));
        }

        return true;
    }

    public function save() : ?CompoundTag{
        return $this->saveVariant(parent::save());
    }

    public function load(CompoundTag $tag) : void{
        parent::load($tag);
        $this->loadVariant($tag);
    }
}