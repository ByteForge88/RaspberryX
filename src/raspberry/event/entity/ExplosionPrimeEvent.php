<?php

declare(strict_types=1);

namespace raspberry\event\entity;

use raspberry\entity\Entity;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;

/**
 * Lit TNT is about to blow up. Change how big the blast is, or whether it breaks blocks.
 * Cancelling it makes it go out without exploding.
 */
class ExplosionPrimeEvent extends EntityEvent implements Cancellable{
    use CancellableTrait;

    private float $radius;

    private bool $blockBreaking = true;

    public function __construct(Entity $entity, float $radius){
        $this->entity = $entity;
        $this->radius = $radius;
    }

    public function getRadius() : float{
        return $this->radius;
    }

    public function setRadius(float $radius) : void{
        $this->radius = $radius;
    }

    public function isBlockBreaking() : bool{
        return $this->blockBreaking;
    }

    public function setBlockBreaking(bool $blockBreaking) : void{
        $this->blockBreaking = $blockBreaking;
    }
}