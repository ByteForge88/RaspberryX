<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\item\Item;

use raspberry\math\Facing;

use raspberry\nbt\tag\CompoundTag;

use raspberry\player\Player;

class Skull extends BlockEntity{

    private float $rotation = 0.0;

    public function getSaveId() : string{
        return "Skull";
    }

    public function readData(CompoundTag $nbt) : void{
        $this->rotation = $nbt->getFloat("Rotation", 0.0) ?? 0.0;
    }

    public function writeData(CompoundTag $nbt) : void{
        $nbt->setByte("SkullType", -1);
        $nbt->setFloat("Rotation", $this->rotation);
    }

    public function onPlace(Item $item, Player $player, int $face) : void{
        $this->rotation = $face === Facing::UP ? Facing::rotationFromYaw($player->getYaw()) * 22.5 : 0.0;
    }
}