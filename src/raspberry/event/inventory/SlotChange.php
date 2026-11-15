<?php

declare(strict_types=1);

namespace raspberry\event\inventory;

use raspberry\inventory\Inventory;

use raspberry\item\Item;

/**
 * One slot an inventory request changed.
 */
class SlotChange{

    private Inventory $inventory;

    private int $slot;

    private Item $before;

    private Item $after;

    public function __construct(Inventory $inventory, int $slot, Item $before, Item $after){
        $this->inventory = $inventory;
        $this->slot = $slot;
        $this->before = $before;
        $this->after = $after;
    }

    public function getInventory() : Inventory{
        return $this->inventory;
    }

    public function getSlot() : int{
        return $this->slot;
    }

    public function getBefore() : Item{
        return $this->before;
    }

    public function getAfter() : Item{
        return $this->after;
    }
}