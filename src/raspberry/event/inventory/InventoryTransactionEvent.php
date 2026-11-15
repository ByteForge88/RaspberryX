<?php

declare(strict_types=1);

namespace raspberry\event\inventory;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;
use raspberry\event\player\PlayerEvent;

use raspberry\item\Item;

use raspberry\player\Player;

/**
 * A player moved items around: between slots, in and out of chests, crafting, dropping from
 * the inventory screen. The slots already show the change, cancelling it puts everything back.
 */
class InventoryTransactionEvent extends PlayerEvent implements Cancellable{
    use CancellableTrait;

    /** @var list<SlotChange> */
    private array $changes;

    /** @var list<Item> */
    private array $drops;

    /**
     * @param list<SlotChange> $changes
     * @param list<Item>       $drops
     */
    public function __construct(Player $player, array $changes, array $drops){
        $this->player = $player;
        $this->changes = $changes;
        $this->drops = $drops;
    }

    /**
     * @return list<SlotChange>
     */
    public function getChanges() : array{
        return $this->changes;
    }

    /**
     * What the player threw out of the screen.
     *
     * @return list<Item>
     */
    public function getDrops() : array{
        return $this->drops;
    }
}