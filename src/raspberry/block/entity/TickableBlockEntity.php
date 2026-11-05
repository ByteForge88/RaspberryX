<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\world\World;

/**
 * A block entity that does something every tick (furnaces cook, hoppers move items).
 */
interface TickableBlockEntity{

    public function tick(World $world, int $currentTick) : void;

}