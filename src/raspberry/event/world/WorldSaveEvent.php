<?php

declare(strict_types=1);

namespace raspberry\event\world;

use raspberry\world\World;

/**
 * A world is about to be saved, by an autosave, /save-all or the server stopping.
 */
class WorldSaveEvent extends WorldEvent{

    public function __construct(World $world){
        $this->world = $world;
    }
}