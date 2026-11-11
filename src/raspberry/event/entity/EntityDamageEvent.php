<?php

declare(strict_types=1);

namespace raspberry\event\entity;

use raspberry\entity\damage\DamageSource;
use raspberry\entity\Living;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;
use raspberry\event\Event;

/**
 * Something is about to hurt an entity, before armor, effects and the time after a hit count.
 * Change the damage with getSource()->setAmount(). Hits from another entity come with
 * getSource()->getAttacker().
 */
class EntityDamageEvent extends Event implements Cancellable{
    use CancellableTrait;

    private Living $entity;

    private DamageSource $source;

    public function __construct(Living $entity, DamageSource $source){
        $this->entity = $entity;
        $this->source = $source;
    }

    public function getEntity() : Living{
        return $this->entity;
    }

    public function getSource() : DamageSource{
        return $this->source;
    }
}