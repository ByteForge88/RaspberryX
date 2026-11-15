<?php

declare(strict_types=1);

namespace raspberry\event\entity;

use raspberry\entity\Living;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;

/**
 * An entity is about to catch fire.
 */
class EntityCombustEvent extends EntityEvent implements Cancellable{
    use CancellableTrait;

    private int $seconds;

    public function __construct(Living $entity, int $seconds){
        $this->entity = $entity;
        $this->seconds = $seconds;
    }

    public function getEntity() : Living{
        /** @var Living */
        return $this->entity;
    }

    public function getSeconds() : int{
        return $this->seconds;
    }

    public function setSeconds(int $seconds) : void{
        $this->seconds = $seconds;
    }
}