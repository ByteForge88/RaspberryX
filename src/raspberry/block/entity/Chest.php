<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\nbt\tag\CompoundTag;

use raspberry\world\World;

class Chest extends ContainerBlockEntity{

    /** @var array{int, int}|null */
    private ?array $pair = null;

    public function getSaveId() : string{
        return "Chest";
    }

    /**
     * @return array{int, int}|null
     */
    public function getPair() : ?array{
        return $this->pair;
    }

    public function isPaired() : bool{
        return $this->pair !== null;
    }

    public function pairWith(Chest $other) : void{
        $this->pair = [$other->getX(), $other->getZ()];
        $other->pair = [$this->getX(), $this->getZ()];
        $this->setChanged();
        $other->setChanged();
    }

    public function unpair() : void{
        $this->pair = null;
        $this->setChanged();
    }

    public function readData(CompoundTag $nbt) : void{
        parent::readData($nbt);

        $pairX = $nbt->getInt("pairx");
        $pairZ = $nbt->getInt("pairz");
        $this->pair = $pairX !== null && $pairZ !== null ? [$pairX, $pairZ] : null;
    }

    public function writeData(CompoundTag $nbt) : void{
        parent::writeData($nbt);

        if($this->pair !== null){
            $nbt->setInt("pairx", $this->pair[0]);
            $nbt->setInt("pairz", $this->pair[1]);
        }
    }

    public function onBreak(World $world) : array{
        if($this->pair !== null){
            [$x, $z] = $this->pair;
            $other = $world->isChunkLoaded($x >> 4, $z >> 4) ? $world->getBlockEntity($x, $this->getY(), $z) : null;

            if($other instanceof Chest){
                $other->unpair();
                $world->sendBlockEntity($other);
            }
        }

        return parent::onBreak($world);
    }
}