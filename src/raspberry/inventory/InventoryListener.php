<?php

declare(strict_types=1);

namespace raspberry\inventory;

use raspberry\item\Item;

/**
 * Told about every change to an inventory, e.g. to send it to the player looking at it.
 */
interface InventoryListener{

    public function onSlotChange(Inventory $inventory, int $slot, Item $oldItem) : void;

    /**
     * Many slots changed at once (setContents(), clearAll()).
     */
    public function onContentChange(Inventory $inventory) : void;

}