<?php

declare(strict_types=1);

namespace raspberry\event\player;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;

use raspberry\item\Item;

use raspberry\player\Player;

/**
 * A player finished eating or drinking. Cancelling it keeps the item and its effects away.
 */
class PlayerItemConsumeEvent extends PlayerEvent implements Cancellable{
    use CancellableTrait;

    private Item $item;

    public function __construct(Player $player, Item $item){
        $this->player = $player;
        $this->item = $item;
    }

    public function getItem() : Item{
        return $this->item;
    }
}