<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use raspberry\nbt\tag\CompoundTag;

use raspberry\player\Player;

class Bed extends BlockEntity{

    private int $color = 14;

    public function getSaveId() : string{
        return "Bed";
    }

    public function getColor() : int{
        return $this->color;
    }

    public function readData(CompoundTag $nbt) : void{
        $this->color = ($nbt->getByte("color", 14) ?? 14) & 15;
    }

    public function writeData(CompoundTag $nbt) : void{
        $nbt->setByte("color", $this->color);
    }

    public function onPlace(Item $item, Player $player, int $face) : void{
        $this->color = $item->getMeta() & 15;
    }

    public function getDrops(array $drops) : array{
        foreach($drops as $i => $drop){
            if($drop->getId() === ItemIds::BED){
                $drops[$i] = new Item(ItemIds::BED, $drop->getCount(), $this->color);
            }
        }

        return $drops;
    }
}