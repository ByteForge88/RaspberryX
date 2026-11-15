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
 * A sapling is about to grow into a tree. getBlocks() is every block the tree sets, the sapling
 * turning into the trunk among them. Change the list to change the tree.
 */
class StructureGrowEvent extends Event implements Cancellable{
    use CancellableTrait;

    private World $world;

    private Vector3 $position;

    private BlockState $sapling;

    /** @var list<array{int, int, int, BlockState}> */
    private array $blocks;

    /**
     * @param list<array{int, int, int, BlockState}> $blocks x, y, z and the block
     */
    public function __construct(World $world, Vector3 $position, BlockState $sapling, array $blocks){
        $this->world = $world;
        $this->position = $position;
        $this->sapling = $sapling;
        $this->blocks = $blocks;
    }

    public function getWorld() : World{
        return $this->world;
    }

    public function getPosition() : Vector3{
        return $this->position;
    }

    public function getSapling() : BlockState{
        return $this->sapling;
    }

    /**
     * @return list<array{int, int, int, BlockState}>
     */
    public function getBlocks() : array{
        return $this->blocks;
    }

    /**
     * @param list<array{int, int, int, BlockState}> $blocks
     */
    public function setBlocks(array $blocks) : void{
        $this->blocks = $blocks;
    }
}