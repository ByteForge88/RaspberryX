<?php

declare(strict_types=1);

namespace raspberry\event\entity;

use raspberry\entity\Entity;

/**
 * An entity is leaving the world: it died, was picked up, blew up, or its chunk unloaded.
 */
class EntityDespawnEvent extends EntityEvent{

    public function __construct(Entity $entity){
        $this->entity = $entity;
    }
}