<?php

declare(strict_types=1);

namespace raspberry\event\world;

use raspberry\world\World;

/**
 * A world was loaded or made.
 */
class WorldLoadEvent extends WorldEvent{

    public function __construct(World $world){
        $this->world = $world;
    }
}