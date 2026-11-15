<?php

declare(strict_types=1);

namespace raspberry\event\entity;

use raspberry\block\BlockState;

use raspberry\entity\Entity;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;

use raspberry\math\Vector3;

/**
 * An entity changes a block: a falling block lands and becomes the block again. Cancelling it
 * drops it as an item.
 */
class EntityBlockChangeEvent extends EntityEvent implements Cancellable{
    use CancellableTrait;

    private Vector3 $position;

    private BlockState $block;

    private BlockState $newState;

    public function __construct(Entity $entity, Vector3 $position, BlockState $block, BlockState $newState){
        $this->entity = $entity;
        $this->position = $position;
        $this->block = $block;
        $this->newState = $newState;
    }

    public function getPosition() : Vector3{
        return $this->position;
    }

    /**
     * The block there now.
     */
    public function getBlock() : BlockState{
        return $this->block;
    }

    public function getNewState() : BlockState{
        return $this->newState;
    }

    public function setNewState(BlockState $newState) : void{
        $this->newState = $newState;
    }
}