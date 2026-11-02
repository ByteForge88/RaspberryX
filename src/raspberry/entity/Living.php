<?php

declare(strict_types=1);

namespace raspberry\entity;

use raspberry\entity\damage\DamageSource;

use function max;
use function min;

abstract class Living extends Entity{

    protected float $maxHealth = 20.0;

    protected float $health = 20.0;

    public const DEFAULT_MOVEMENT_SPEED = 0.1;

    protected ?DamageSource $lastDamageSource = null;

    /** extra hearts on top of health, e.g. from golden apples */
    protected float $absorption = 0.0;

    protected float $movementSpeed = self::DEFAULT_MOVEMENT_SPEED;

    public function getHealth() : float{
        return $this->health;
    }

    public function setHealth(float $health) : void{
        $wasAlive = $this->health > 0;

        $this->health = max(0.0, min($health, $this->maxHealth));
        $this->onAttributesChanged();

        if($wasAlive && $this->health <= 0){
            $this->onDeath();
        }
    }

    public function kill() : void{
        $this->setHealth(0.0);
    }


    /**
     * Hurts the entity. Absorption is used up before health. Does nothing when the entity is dead
     * or cannot be hurt by this source (see canBeDamagedBy())
     */
    public function damage(DamageSource $source) : void{
        if(!$this->isAlive() || $source->getAmount() <= 0 || !$this->canBeDamagedBy($source)){
            return;
        }

        $amount = $source->getAmount();
        $absorbed = min($amount, $this->absorption);
        $this->absorption -= $absorbed;

        $this->lastDamageSource = $source;
        $this->onDamaged($source);

        $this->setHealth($this->health - ($amount - $absorbed));
    }

    /**
     * Adds health, up to the maximum. Does nothing to a dead entity.
     */
    public function heal(float $amount) : void{
        if($this->isAlive() && $amount > 0){
            $this->setHealth($this->health + $amount);
        }
    }

    /**
     * The last hit the entity took, null if it has not been hurt since it spawned.
     */
    public function getLastDamageSource() : ?DamageSource{
        return $this->lastDamageSource;
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

    protected function canBeDamagedBy(DamageSource $source) : bool{
        return true;
    }

    /**
     * Called for every hit, before the health goes down.
     */
    protected function onDamaged(DamageSource $source) : void{

    }

    protected function onDeath() : void{

    }

    protected function onAttributesChanged() : void{

    }
}