<?php

declare(strict_types=1);

namespace raspberry\event\player;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;

use raspberry\item\Item;

use raspberry\player\Player;

/**
 * A player picked another hotbar slot. Cancelling it keeps the old slot.
 */
class PlayerItemHeldEvent extends PlayerEvent implements Cancellable{
    use CancellableTrait;

    private int $slot;

    private Item $item;

    public function __construct(Player $player, int $slot, Item $item){
        $this->player = $player;
        $this->slot = $slot;
        $this->item = $item;
    }

    /**
     * The hotbar slot, 0 to 8.
     */
    public function getSlot() : int{
        return $this->slot;
    }

    public function getItem() : Item{
        return $this->item;
    }
}