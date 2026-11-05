<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\item\Item;

use raspberry\math\Facing;

use raspberry\nbt\tag\CompoundTag;

use raspberry\player\Player;

use raspberry\world\World;

class ShulkerBox extends ContainerBlockEntity{

    /** the side the lid opens to */
    private int $facing = Facing::UP;

    public function getSaveId() : string{
        return "ShulkerBox";
    }

    public function getFacing() : int{
        return $this->facing;
    }

    public function readData(CompoundTag $nbt) : void{
        parent::readData($nbt);
        $facing = $nbt->getByte("facing", Facing::UP) ?? Facing::UP;
        $this->facing = $facing >= Facing::DOWN && $facing <= Facing::EAST ? $facing : Facing::UP;
    }

    public function writeData(CompoundTag $nbt) : void{
        parent::writeData($nbt);
        $nbt->setByte("facing", $this->facing);
    }

    public function onPlace(Item $item, Player $player, int $face) : void{
        parent::onPlace($item, $player, $face);
        $this->facing = $face;
        $this->getInventory()->setContents(self::readItems($item->getExtraTags()));
    }

    public function getDrops(array $drops) : array{
        foreach($drops as $drop){
            if($this->getInventory()->getContents() !== []){
                $drop->getExtraTags()->setTag("Items", self::writeItems($this->getInventory()->getContents()));
            }

            if($this->getCustomName() !== null){
                $drop->setCustomName($this->getCustomName());
            }
        }

        return $drops;
    }

    public function onBreak(World $world) : array{
        return [];
    }
}