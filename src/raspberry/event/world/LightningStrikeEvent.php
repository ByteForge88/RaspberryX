<?php

declare(strict_types=1);

namespace raspberry\event\world;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;

use raspberry\math\Vector3;

use raspberry\world\World;

/**
 * A storm is about to strike lightning. Move it with setPosition().
 */
class LightningStrikeEvent extends WorldEvent implements Cancellable{
    use CancellableTrait;

    private Vector3 $position;

    public function __construct(World $world, Vector3 $position){
        $this->world = $world;
        $this->position = $position;
    }

    public function getPosition() : Vector3{
        return $this->position;
    }

    public function setPosition(Vector3 $position) : void{
        $this->position = $position;
    }
}