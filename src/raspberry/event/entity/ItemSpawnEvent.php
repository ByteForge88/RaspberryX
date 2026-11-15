<?php

declare(strict_types=1);

namespace raspberry\event\entity;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;
use raspberry\event\Event;

use raspberry\item\Item;

use raspberry\math\Vector3;

use raspberry\world\World;

/**
 * An item is about to drop in the world: from a broken block, a dead mob, a player.
 */
class ItemSpawnEvent extends Event implements Cancellable{
    use CancellableTrait;

    private World $world;

    private Vector3 $position;

    private Item $item;

    public function __construct(World $world, Vector3 $position, Item $item){
        $this->world = $world;
        $this->position = $position;
        $this->item = $item;
    }

    public function getWorld() : World{
        return $this->world;
    }

    public function getPosition() : Vector3{
        return $this->position;
    }

    public function getItem() : Item{
        return $this->item;
    }

    public function setItem(Item $item) : void{
        $this->item = $item;
    }
}