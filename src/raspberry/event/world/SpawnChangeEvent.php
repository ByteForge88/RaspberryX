<?php

declare(strict_types=1);

namespace raspberry\event\world;

use raspberry\math\Vector3;

use raspberry\world\World;

/**
 * The world's spawn point moved.
 */
class SpawnChangeEvent extends WorldEvent{

    private ?Vector3 $oldSpawn;

    public function __construct(World $world, ?Vector3 $oldSpawn){
        $this->world = $world;
        $this->oldSpawn = $oldSpawn;
    }

    public function getOldSpawn() : ?Vector3{
        return $this->oldSpawn;
    }

    public function getNewSpawn() : Vector3{
        return $this->world->getSpawnPosition();
    }
}