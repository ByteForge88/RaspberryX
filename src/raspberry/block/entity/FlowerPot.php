<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\Server;

use raspberry\block\BlockState;

use raspberry\item\Item;

use raspberry\nbt\tag\CompoundTag;

use raspberry\world\World;

use function explode;
use function is_bool;
use function is_int;
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

        $states = new CompoundTag();

        foreach($this->plant->getProperties() as $property => $value){
            match(true){
                is_bool($value) => $states->setByte($property, $value ? 1 : 0),
                is_int($value) => $states->setInt($property, $value),
                default => $states->setString($property, $value)
            };
        }

        $nbt->setTag("PlantBlock", (new CompoundTag())
            ->setString("name", $this->plant->getName())
            ->setTag("states", $states)
            ->setInt("version", self::getStateVersion()));
    }

    public function onBreak(World $world) : array{
        $plant = $this->plant;
        $this->plant = null;

        return $plant === null ? [] : [new Item($plant->getName())];
    }

    private static function getStateVersion() : int{
        $version = 0;

        foreach(explode(".", Server::getInstance()->getBlockStateRegistry()->getVersion()) as $part){
            $version = $version << 8 | ((int) $part & 0xff);
        }

        return $version;
    }
}