<?php

declare(strict_types=1);

namespace raspberry\entity;

use function max;
use function min;

/**
 * An entity with health that can be hurt and die.
 */
abstract class Living extends Entity{

    protected float $maxHealth = 20.0;

    protected float $health = 20.0;

    public function getHealth() : float{
        return $this->health;
    }

    public function setHealth(float $health) : void{
        $this->health = max(0.0, min($health, $this->maxHealth));
    }

    public function getMaxHealth() : float{
        return $this->maxHealth;
    }

    public function setMaxHealth(float $maxHealth) : void{
        $this->maxHealth = max(1.0, $maxHealth);
        $this->health = min($this->health, $this->maxHealth);
    }

    public function isAlive() : bool{
        return $this->health > 0;
    }
}