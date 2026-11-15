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
 * A block is about to change by itself: a crop grows, ice melts, grass spreads. Change what it
 * turns into with setNewState(), or cancel it to keep the block as it is.
 */
abstract class BlockChangeEvent extends Event implements Cancellable{
    use CancellableTrait;

    protected World $world;

    protected int $x;

    protected int $y;

    protected int $z;

    protected BlockState $block;

    protected BlockState $newState;

    public function __construct(World $world, int $x, int $y, int $z, BlockState $newState){
        $this->world = $world;
        $this->x = $x;
        $this->y = $y;
        $this->z = $z;
        $this->block = $world->getBlock($x, $y, $z);
        $this->newState = $newState;
    }

    /**
     * @return bool
     */
    public static function set(World $world, int $x, int $y, int $z, BlockState $newState) : bool{
        if(!static::hasHandlers()){
            $world->setBlock($x, $y, $z, $newState);
            return true;
        }

        $event = new static($world, $x, $y, $z, $newState);
        $event->call();

        if($event->isCancelled()){
            return false;
        }

        $world->setBlock($x, $y, $z, $event->getNewState());

        return true;
    }

    public function getWorld() : World{
        return $this->world;
    }

    public function getPosition() : Vector3{
        return new Vector3($this->x, $this->y, $this->z);
    }

    public function getX() : int{
        return $this->x;
    }

    public function getY() : int{
        return $this->y;
    }

    public function getZ() : int{
        return $this->z;
    }

    /**
     * The block as it is now.
     */
    public function getBlock() : BlockState{
        return $this->block;
    }

    /**
     * What the block turns into.
     */
    public function getNewState() : BlockState{
        return $this->newState;
    }

    public function setNewState(BlockState $newState) : void{
        $this->newState = $newState;
    }
}