<?php

declare(strict_types=1);

namespace raspberry\event\block;

use raspberry\block\BlockState;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;
use raspberry\event\Event;

use raspberry\item\Item;

use raspberry\math\Vector3;

use raspberry\player\Player;

/**
 * A player is about to place a block. Cancelling it keeps the item and takes the block back
 * for the player.
 */
class BlockPlaceEvent extends Event implements Cancellable{
    use CancellableTrait;

    private Player $player;

    private BlockState $block;

    private Vector3 $position;

    private Item $item;

    public function __construct(Player $player, BlockState $block, Vector3 $position, Item $item){
        $this->player = $player;
        $this->block = $block;
        $this->position = $position;
        $this->item = $item;
    }

    public function getPlayer() : Player{
        return $this->player;
    }

    /**
     * The block as it will be placed, turned to face the right way.
     */
    public function getBlock() : BlockState{
        return $this->block;
    }

    public function getPosition() : Vector3{
        return $this->position;
    }

    public function getItem() : Item{
        return $this->item;
    }
}