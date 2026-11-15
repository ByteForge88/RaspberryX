<?php

declare(strict_types=1);

namespace raspberry\event\inventory;

use raspberry\block\BlockState;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;
use raspberry\event\player\PlayerEvent;

use raspberry\math\Vector3;

use raspberry\player\Player;

/**
 * A player is about to open a chest, furnace, crafting table or any other block with a screen.
 * Cancelling it keeps the screen shut.
 */
class InventoryOpenEvent extends PlayerEvent implements Cancellable{
    use CancellableTrait;

    private BlockState $block;

    private Vector3 $position;

    public function __construct(Player $player, BlockState $block, Vector3 $position){
        $this->player = $player;
        $this->block = $block;
        $this->position = $position;
    }

    public function getBlock() : BlockState{
        return $this->block;
    }

    public function getPosition() : Vector3{
        return $this->position;
    }
}