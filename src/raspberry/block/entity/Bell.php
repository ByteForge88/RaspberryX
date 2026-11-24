<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\nbt\tag\CompoundTag;

use raspberry\world\World;

class Bell extends BlockEntity implements TickableBlockEntity{

    public const string ID = "Bell";

    public const int RING_TICKS = 50;

    private bool $ringing = false;

    private int $ticks = 0;

    private int $direction = 0;

    public function getSaveId() : string{
        return self::ID;
    }

    public function isRinging() : bool{
        return $this->ringing;
    }

    public function ring(int $direction) : void{
        $this->ringing = true;
        $this->ticks = 0;
        $this->direction = $direction;
        $this->setChanged();
    }

    public function tick(World $world, int $currentTick) : void{
        if(!$this->ringing || ++$this->ticks < self::RING_TICKS){
            return;
        }

        $this->ringing = false;
        $this->ticks = 0;
        $this->setChanged();
        $world->sendBlockEntity($this);
    }

    public function readData(CompoundTag $nbt) : void{
        $this->ringing = $nbt->getByte("Ringing", 0) === 1;
        $this->ticks = $nbt->getInt("Ticks", 0) ?? 0;
        $this->direction = $nbt->getInt("Direction", 0) ?? 0;
    }

    public function writeData(CompoundTag $nbt) : void{
        $nbt->setByte("Ringing", $this->ringing ? 1 : 0)
            ->setInt("Ticks", $this->ticks)
            ->setInt("Direction", $this->direction);
    }
}