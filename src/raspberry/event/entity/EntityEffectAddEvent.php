<?php

declare(strict_types=1);

namespace raspberry\event\entity;

use raspberry\entity\Living;
use raspberry\entity\effect\EffectInstance;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;

/**
 * An entity is about to get an effect, or a stronger or longer one than it has.
 */
class EntityEffectAddEvent extends EntityEvent implements Cancellable{
    use CancellableTrait;

    private EffectInstance $effect;

    private ?EffectInstance $oldEffect;

    public function __construct(Living $entity, EffectInstance $effect, ?EffectInstance $oldEffect){
        $this->entity = $entity;
        $this->effect = $effect;
        $this->oldEffect = $oldEffect;
    }

    public function getEntity() : Living{
        /** @var Living */
        return $this->entity;
    }

    public function getEffect() : EffectInstance{
        return $this->effect;
    }

    /**
     * The one it replaces, null when the entity did not have this effect.
     */
    public function getOldEffect() : ?EffectInstance{
        return $this->oldEffect;
    }
}