<?php

declare(strict_types=1);

namespace raspberry\event\player;

use raspberry\block\BlockState;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;

use raspberry\item\Item;

use raspberry\math\Vector3;

use raspberry\player\Player;

/**
 * A player clicked a block, before anything happens: using the block, placing, flint and
 * steel, buckets, bone meal. Cancelling a right click stops all of it, cancelling a left click
 * stops the block from being broken.
 */
class PlayerInteractEvent extends PlayerEvent implements Cancellable{
    use CancellableTrait;

    public const LEFT_CLICK_BLOCK = 0;
    public const RIGHT_CLICK_BLOCK = 1;

    private BlockState $block;

    private Vector3 $position;

    private int $face;

    private Item $item;

    private int $action;

    public function __construct(Player $player, BlockState $block, Vector3 $position, int $face, Item $item, int $action){
        $this->player = $player;
        $this->block = $block;
        $this->position = $position;
        $this->face = $face;
        $this->item = $item;
        $this->action = $action;
    }

    public function getBlock() : BlockState{
        return $this->block;
    }

    public function getPosition() : Vector3{
        return $this->position;
    }

    /**
     * The side clicked, a Facing constant.
     */
    public function getFace() : int{
        return $this->face;
    }

    public function getItem() : Item{
        return $this->item;
    }

    /**
     * LEFT_CLICK_BLOCK or RIGHT_CLICK_BLOCK.
     */
    public function getAction() : int{
        return $this->action;
    }
}