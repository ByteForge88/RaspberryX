<?php

declare(strict_types=1);

namespace raspberry\event\entity;

use raspberry\entity\Living;
use raspberry\entity\effect\EffectInstance;

/**
 * An effect ran out or was taken away, milk, a command, death.
 */
class EntityEffectRemoveEvent extends EntityEvent{

    private EffectInstance $effect;

    public function __construct(Living $entity, EffectInstance $effect){
        $this->entity = $entity;
        $this->effect = $effect;
    }

    public function getEntity() : Living{
        /** @var Living */
        return $this->entity;
    }

    public function getEffect() : EffectInstance{
        return $this->effect;
    }
}