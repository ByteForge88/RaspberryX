<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\block\BlockNBT;
use raspberry\block\BlockState;
use raspberry\block\Pistons;
use raspberry\block\redstone\RedstoneUpdater;

use raspberry\nbt\tag\CompoundTag;

use raspberry\world\World;

class MovingBlock extends BlockEntity implements TickableBlockEntity{

    public const string ID = "MovingBlock";

    private ?BlockState $block = null;

    private ?CompoundTag $entityData = null;

    private int $pistonX = 0;
    private int $pistonY = 0;
    private int $pistonZ = 0;

    private bool $expanding = true;

    public function getSaveId() : string{
        return self::ID;
    }

    public function setMoving(BlockState $block, ?CompoundTag $entityData, int $pistonX, int $pistonY, int $pistonZ, bool $expanding) : void{
        $this->block = $block;
        $this->entityData = $entityData;
        $this->pistonX = $pistonX;
        $this->pistonY = $pistonY;
        $this->pistonZ = $pistonZ;
        $this->expanding = $expanding;
        $this->setChanged();
    }

    public function getBlock() : BlockState{
        return $this->block ?? RedstoneUpdater::air();
    }

    public function getEntityData() : ?CompoundTag{
        return $this->entityData === null ? null : clone $this->entityData;
    }

    public function getPistonX() : int{
        return $this->pistonX;
    }

    public function getPistonY() : int{
        return $this->pistonY;
    }

    public function getPistonZ() : int{
        return $this->pistonZ;
    }

    public function tick(World $world, int $currentTick) : void{
        if(!Pistons::isMovedBy($world, $this)){
            Pistons::settle($world, $this);
        }
    }

    public function readData(CompoundTag $nbt) : void{
        $this->block = BlockNBT::read($nbt->getCompoundTag("movingBlock"));
        $this->entityData = $nbt->getCompoundTag("movingEntity");
        $this->pistonX = $nbt->getInt("pistonPosX", 0) ?? 0;
        $this->pistonY = $nbt->getInt("pistonPosY", 0) ?? 0;
        $this->pistonZ = $nbt->getInt("pistonPosZ", 0) ?? 0;
        $this->expanding = $nbt->getByte("expanding", 1) === 1;
    }

    public function writeData(CompoundTag $nbt) : void{
        $nbt->setTag("movingBlock", BlockNBT::write($this->getBlock()))
            ->setTag("movingBlockExtra", BlockNBT::write(RedstoneUpdater::air()))
            ->setInt("pistonPosX", $this->pistonX)
            ->setInt("pistonPosY", $this->pistonY)
            ->setInt("pistonPosZ", $this->pistonZ)
            ->setByte("expanding", $this->expanding ? 1 : 0);

        if($this->entityData !== null){
            $nbt->setTag("movingEntity", $this->entityData);
        }
    }
}