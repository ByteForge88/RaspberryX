<?php

declare(strict_types=1);

namespace raspberry\entity;

use raspberry\entity\effect\Effect;
use raspberry\entity\effect\EffectInstance;
use raspberry\entity\effect\EffectManager;
use raspberry\entity\damage\DamageSource;

use raspberry\math\Vector3;

use raspberry\world\World;

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

    protected EffectManager $effects;

    public function __construct(World $world, Vector3 $position){
        parent::__construct($world, $position);
        $this->effects = new EffectManager($this);
    }

    public function getEffects() : EffectManager{
        return $this->effects;
    }

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

        $amount = $this->reduceDamage($source);

        if($amount <= 0){
            return;
        }

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
        return !$this->effects->has(Effect::FIRE_RESISTANCE) || !$source->isFire();
    }

    /**
     * Resistance takes a fifth off for each level, five levels stop everything but the void.
     */
    protected function reduceDamage(DamageSource $source) : float{
        $resistance = $this->effects->getLevel(Effect::RESISTANCE);

        if($resistance === 0 || $source->bypassesGameMode()){
            return $source->getAmount();
        }

        return $source->getAmount() * max(0.0, 1.0 - 0.2 * $resistance);
    }

    /**
     * @internal
     */
    public function onEffectAdded(EffectInstance $effect, ?EffectInstance $old) : void{
        if($effect->getEffect() === Effect::ABSORPTION){
            $this->setAbsorption(max($this->absorption, 4.0 * $effect->getLevel()));
        }

        $this->updateEffectAttributes();
    }

    /**
     * @internal
     */
    public function onEffectRemoved(EffectInstance $effect) : void{
        if($effect->getEffect() === Effect::ABSORPTION){
            $this->setAbsorption(0.0);
        }

        $this->updateEffectAttributes();
    }

    /**
     * Speed, slowness and health boost change the attributes while they last.
     */
    protected function updateEffectAttributes() : void{
        $speed = self::DEFAULT_MOVEMENT_SPEED * (1.0 + 0.2 * $this->effects->getLevel(Effect::SPEED)) * max(0.0, 1.0 - 0.15 * $this->effects->getLevel(Effect::SLOWNESS));
        $maxHealth = 20.0 + 4.0 * $this->effects->getLevel(Effect::HEALTH_BOOST);

        if($speed !== $this->movementSpeed || $maxHealth !== $this->maxHealth){
            $this->movementSpeed = $speed;
            $this->setMaxHealth($maxHealth);
        }
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