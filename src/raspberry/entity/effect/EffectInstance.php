<?php

declare(strict_types=1);

namespace raspberry\entity\effect;

use function max;
use function min;

class EffectInstance{

    public const MAX_DURATION = 2147483647;

    private Effect $effect;

    private int $duration;

    private int $amplifier;

    private bool $ambient;

    private bool $visible;

    /**
     * @param int $duration  in ticks
     * @param int $amplifier the level minus one
     * @param bool $ambient  fainter particles, like the ones from a beacon
     */
    public function __construct(Effect $effect, int $duration, int $amplifier = 0, bool $ambient = false, bool $visible = true){
        $this->effect = $effect;
        $this->duration = max(0, min($duration, self::MAX_DURATION));
        $this->amplifier = max(0, min($amplifier, 255));
        $this->ambient = $ambient;
        $this->visible = $visible;
    }

    public function getEffect() : Effect{
        return $this->effect;
    }

    public function getDuration() : int{
        return $this->duration;
    }

    public function setDuration(int $duration) : self{
        $this->duration = max(0, min($duration, self::MAX_DURATION));
        return $this;
    }

    public function getAmplifier() : int{
        return $this->amplifier;
    }

    public function getLevel() : int{
        return $this->amplifier + 1;
    }

    public function isAmbient() : bool{
        return $this->ambient;
    }

    public function isVisible() : bool{
        return $this->visible;
    }
}