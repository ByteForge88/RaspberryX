<?php

declare(strict_types=1);

namespace raspberry\event\block;

use raspberry\block\BlockState;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;
use raspberry\event\Event;

use raspberry\item\Item;

use raspberry\math\Vector3;

use raspberry\world\World;

/**
 * A block lost what holds it and is about to pop off: a torch whose wall went, a flower whose
 * grass was dug up, a lantern under a broken ceiling. Cancelling it keeps it floating there.
 */
class BlockDetachEvent extends Event implements Cancellable{
    use CancellableTrait;

    private World $world;

    private Vector3 $position;

    private BlockState $block;

    /** @var list<Item> */
    private array $drops;

    /**
     * @param list<Item> $drops
     */
    public function __construct(World $world, Vector3 $position, BlockState $block, array $drops){
        $this->world = $world;
        $this->position = $position;
        $this->block = $block;
        $this->drops = $drops;
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

    /**
     * @return list<Item>
     */
    public function getDrops() : array{
        return $this->drops;
    }

    /**
     * @param list<Item> $drops
     */
    public function setDrops(array $drops) : void{
        $this->drops = $drops;
    }
}