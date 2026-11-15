<?php

declare(strict_types=1);

namespace raspberry\event\block;

use raspberry\block\BlockState;

use raspberry\math\Vector3;

use raspberry\world\World;

/**
 * Grass or mycelium spreads to the dirt next to it. getSource() is where it spreads from.
 */
class BlockSpreadEvent extends BlockChangeEvent{

    private Vector3 $source;

    public function __construct(World $world, int $x, int $y, int $z, BlockState $newState, ?Vector3 $source = null){
        parent::__construct($world, $x, $y, $z, $newState);
        $this->source = $source ?? new Vector3($x, $y, $z);
    }

    public static function spread(World $world, int $x, int $y, int $z, BlockState $newState, Vector3 $source) : bool{
        if(!self::hasHandlers()){
            $world->setBlock($x, $y, $z, $newState);
            return true;
        }

        $event = new self($world, $x, $y, $z, $newState, $source);
        $event->call();

        if($event->isCancelled()){
            return false;
        }

        $world->setBlock($x, $y, $z, $event->getNewState());

        return true;
    }

    public function getSource() : Vector3{
        return $this->source;
    }
}