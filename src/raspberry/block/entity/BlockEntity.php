<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\item\Item;

use raspberry\nbt\tag\CompoundTag;

use raspberry\player\Player;

use raspberry\world\World;

abstract class BlockEntity{

    private int $x;
    private int $y;
    private int $z;

    /** changed since the chunk was last saved */
    private bool $changed = false;

    /** @var array<int, Player> */
    private array $viewers = [];

    public function __construct(int $x, int $y, int $z){
        $this->x = $x;
        $this->y = $y;
        $this->z = $z;
    }

    public function getX() : int{
        return $this->x;
    }

    public function getY() : int{
        return $this->y;
    }

    public function getZ() : int{
        return $this->z;
    }

    /**
     * The "id" in the NBT ("Chest", "Sign"...).
     */
    abstract public function getSaveId() : string;

    /**
     * Reads the data this block entity keeps from saved NBT.
     */
    public function readData(CompoundTag $nbt) : void{
    }

    /**
     * Writes the data this block entity keeps to NBT.
     */
    public function writeData(CompoundTag $nbt) : void{
    }

    /**
     * Everything that is saved: id, position and the data.
     */
    public function save() : CompoundTag{
        $nbt = (new CompoundTag())
            ->setString("id", $this->getSaveId())
            ->setInt("x", $this->x)
            ->setInt("y", $this->y)
            ->setInt("z", $this->z)
            ->setByte("isMovable", 1);

        $this->writeData($nbt);

        return $nbt;
    }

    /**
     * What players are sent.
     */
    public function getSpawnData() : CompoundTag{
        return $this->save();
    }

    /**
     * Called when a player places the block, with the item it was placed from and the clicked face.
     */
    public function onPlace(Item $item, Player $player, int $face) : void{
    }

    /**
     * Changes the items the block drops when broken in survival (a bed keeps its color).
     *
     * @param list<Item> $drops
     *
     * @return list<Item>
     */
    public function getDrops(array $drops) : array{
        return $drops;
    }

    /**
     * @return list<Item>
     */
    public function onBreak(World $world) : array{
        return [];
    }

    /**
     * @return array<int, Player>
     */
    public function getViewers() : array{
        return $this->viewers;
    }

    public function addViewer(Player $player) : void{
        $this->viewers[$player->getId()] = $player;
    }

    public function removeViewer(Player $player) : void{
        unset($this->viewers[$player->getId()]);
    }

    public function isChanged() : bool{
        return $this->changed;
    }

    public function setChanged(bool $changed = true) : void{
        $this->changed = $changed;
    }
}