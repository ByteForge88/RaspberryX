<?php

declare(strict_types=1);

namespace raspberry\event\world;

use raspberry\world\World;

/**
 * A world is about to be saved and unloaded.
 */
class WorldUnloadEvent extends WorldEvent{

    public function __construct(World $world){
        $this->world = $world;
    }
}