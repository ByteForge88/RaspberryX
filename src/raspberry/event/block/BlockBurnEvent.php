<?php

declare(strict_types=1);

namespace raspberry\event\block;

use raspberry\block\BlockState;

use raspberry\math\Vector3;

use raspberry\world\World;

/**
 * Fire burns a block away. It turns into air, or fire when the fire moves on to it. TNT is lit
 * instead of burned, without this event.
 */
class BlockBurnEvent extends BlockChangeEvent{

    private Vector3 $fire;

    public function __construct(World $world, int $x, int $y, int $z, BlockState $newState, Vector3 $fire){
        parent::__construct($world, $x, $y, $z, $newState);
        $this->fire = $fire;
    }

    /**
     * The fire that burned it.
     */
    public function getFire() : Vector3{
        return $this->fire;
    }
}