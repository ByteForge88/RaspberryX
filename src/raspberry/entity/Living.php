<?php

declare(strict_types=1);

namespace raspberry\entity;

use function max;
use function min;

abstract class Living extends Entity{

    protected float $maxHealth = 20.0;

    protected float $health = 20.0;

    public const DEFAULT_MOVEMENT_SPEED = 0.1;

    /** extra hearts on top of health, e.g. from golden apples */
    protected float $absorption = 0.0;

    protected float $movementSpeed = self::DEFAULT_MOVEMENT_SPEED;

    public function getHealth() : float{
        return $this->health;
    }

    public function setHealth(float $health) : void{
        $this->health = max(0.0, min($health, $this->maxHealth));
        $this->onAttributesChanged();
    }

    public function getMaxHealth() : float{
        return $this->maxHealth;
    }

    public function setMaxHealth(float $maxHealth) : void{
        $this->maxHealth = max(1.0, $maxHealth);
        $this->health = min($this->health, $this->maxHealth);
        $this->onAttributesChanged();
    }

    public function getAbsorption() : float{
        return $this->absorption;
    }

    public function setAbsorption(float $absorption) : void{
        $this->absorption = max(0.0, $absorption);
        $this->onAttributesChanged();
    }

    public function getMovementSpeed() : float{
        return $this->movementSpeed;
    }

    public function setMovementSpeed(float $movementSpeed) : void{
        $this->movementSpeed = max(0.0, $movementSpeed);
        $this->onAttributesChanged();
    }

    public function isAlive() : bool{
        return $this->health > 0;
    }

    /**
     * Called after health, absorption, speed (and for humans hunger) changed. Players send the new values.
     */
    protected function onAttributesChanged() : void{

    }
}