<?php

declare(strict_types=1);

namespace raspberry\event\world;

use raspberry\event\Event;

use raspberry\world\World;

abstract class WorldEvent extends Event{

    protected World $world;

    public function getWorld() : World{
        return $this->world;
    }
}