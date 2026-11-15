<?php

declare(strict_types=1);

namespace raspberry\event\entity;

use raspberry\entity\Living;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;

/**
 * An entity is about to heal: from food, regeneration, a potion.
 */
class EntityRegainHealthEvent extends EntityEvent implements Cancellable{
    use CancellableTrait;

    private float $amount;

    public function __construct(Living $entity, float $amount){
        $this->entity = $entity;
        $this->amount = $amount;
    }

    public function getEntity() : Living{
        /** @var Living */
        return $this->entity;
    }

    public function getAmount() : float{
        return $this->amount;
    }

    public function setAmount(float $amount) : void{
        $this->amount = $amount;
    }
}