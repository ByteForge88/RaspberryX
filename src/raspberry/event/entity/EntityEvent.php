<?php

declare(strict_types=1);

namespace raspberry\event\entity;

use raspberry\entity\Entity;

use raspberry\event\Event;

abstract class EntityEvent extends Event{

    protected Entity $entity;

    public function getEntity() : Entity{
        return $this->entity;
    }
}