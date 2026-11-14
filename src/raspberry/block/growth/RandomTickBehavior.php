<?php

declare(strict_types=1);

namespace raspberry\block\growth;

use raspberry\block\BlockState;

use raspberry\world\World;

/**
 * What some blocks do when a random tick picks them: grow, spread, melt, decay.
 */
interface RandomTickBehavior{

    /**
     * @return list<string>
     */
    public function getBlockNames() : array;

    public function ticks(BlockState $state) : bool;

    public function onRandomTick(World $world, int $x, int $y, int $z, BlockState $state) : void;

}