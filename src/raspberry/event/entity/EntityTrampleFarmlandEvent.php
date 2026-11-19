<?php

declare(strict_types=1);

namespace raspberry\event\entity;

use raspberry\entity\Entity;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;

use raspberry\math\Vector3;

/**
 * A player or a mob landed on farmland hard enough to turn it back into dirt.
 */
class EntityTrampleFarmlandEvent extends EntityEvent implements Cancellable{
    use CancellableTrait;

    private Vector3 $position;

    public function __construct(Entity $entity, Vector3 $position){
        $this->entity = $entity;
        $this->position = $position;
    }

    public function getPosition() : Vector3{
        return $this->position;
    }
}