<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\Server;

use raspberry\block\BlockNBT;
use raspberry\block\BlockState;

use raspberry\item\Item;

use raspberry\nbt\tag\CompoundTag;

use raspberry\world\World;

use function preg_match;

class FlowerPot extends BlockEntity{

    /** what can be planted in a pot */
    private const PLANTS = '/^minecraft:(\w+_sapling|mangrove_propagule|(golden_)?dandelion|poppy|blue_orchid|allium|azure_bluet|\w+_tulip|oxeye_daisy|cornflower|lily_of_the_valley|wither_rose|torchflower|(open|closed)_eyeblossom|(brown|red)_mushroom|fern|deadbush|cactus|bamboo|azalea|flowering_azalea|(crimson|warped)_(fungus|roots)|red_shrub)$/D';

    private ?BlockState $plant = null;

    public function getSaveId() : string{
        return "FlowerPot";
    }

    public static function canHold(string $blockName) : bool{
        return preg_match(self::PLANTS, $blockName) === 1;
    }

    public function getPlant() : ?BlockState{
        return $this->plant;
    }

    public function setPlant(?BlockState $plant) : void{
        $this->plant = $plant;
        $this->setChanged();
    }

    public function readData(CompoundTag $nbt) : void{
        $name = $nbt->getCompoundTag("PlantBlock")?->getString("name");
        $registry = Server::getInstance()->getBlockStateRegistry();

        $this->plant = $name !== null && self::canHold($name) && $registry->isRegistered($name) ? $registry->getDefaultState($name) : null;
    }

    public function writeData(CompoundTag $nbt) : void{
        if($this->plant === null){
            return;
        }

        $nbt->setTag("PlantBlock", BlockNBT::write($this->plant));
    }

    public function onBreak(World $world) : array{
        $plant = $this->plant;
        $this->plant = null;
        return $plant === null ? [] : [new Item($plant->getName())];
    }
}