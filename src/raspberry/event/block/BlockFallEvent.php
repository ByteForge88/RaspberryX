<?php

declare(strict_types=1);

namespace raspberry\event\block;

use raspberry\block\BlockState;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;
use raspberry\event\Event;

use raspberry\math\Vector3;

use raspberry\world\World;

/**
 * Sand, gravel, an anvil or another falling block is about to fall. Cancelling it keeps it
 * floating where it is.
 */
class BlockFallEvent extends Event implements Cancellable{
    use CancellableTrait;

    private World $world;

    private Vector3 $position;

    private BlockState $block;

    public function __construct(World $world, Vector3 $position, BlockState $block){
        $this->world = $world;
        $this->position = $position;
        $this->block = $block;
    }

    public function getWorld() : World{
        return $this->world;
    }

    public function getPosition() : Vector3{
        return $this->position;
    }

    public function getBlock() : BlockState{
        return $this->block;
    }
}