<?php

declare(strict_types=1);

namespace raspberry\event\entity;

use raspberry\entity\Entity;

/**
 * An entity came into the world on the tick after it was made or came over from another world:
 * a mob, an item, lit TNT, a lightning bolt. close() it to get rid of it. Players have
 * PlayerJoinEvent instead.
 */
class EntitySpawnEvent extends EntityEvent{

    public function __construct(Entity $entity){
        $this->entity = $entity;
    }
}