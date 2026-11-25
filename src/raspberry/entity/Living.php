<?php

declare(strict_types=1);

namespace raspberry\entity;

use raspberry\entity\effect\Effect;
use raspberry\entity\effect\EffectInstance;
use raspberry\entity\effect\EffectManager;
use raspberry\entity\damage\DamageSource;
use raspberry\entity\monster\Silverfish;

use raspberry\event\entity\EntityCombustEvent;
use raspberry\event\entity\EntityDamageEvent;
use raspberry\event\entity\EntityRegainHealthEvent;

use raspberry\math\Vector3;

use raspberry\world\World;

use function cos;
use function deg2rad;
use function intdiv;
use function max;
use function min;
use function mt_getrandmax;
use function mt_rand;
use function sin;
use function sqrt;

abstract class Living extends Entity{

    public const float DEFAULT_MOVEMENT_SPEED = 0.1;

    public const int INVULNERABLE_TICKS = 10;

    public const float KNOCKBACK = 0.4;

    public const int MAX_AIR = 300;

    private const int AIR_REFILL = 4;

    private const float INFESTED_CHANCE = 0.1;

    private const float DROWNING_DAMAGE = 2.0;

    protected float $maxHealth = 20.0;

    protected float $health = 20.0;

    protected ?DamageSource $lastDamageSource = null;

    /** extra hearts on top of health, e.g. from golden apples */
    protected float $absorption = 0.0;

    protected float $movementSpeed = self::DEFAULT_MOVEMENT_SPEED;

    protected EffectManager $effects;

    protected int $invulnerableTicks = 0;

    protected float $lastDamage = 0.0;

    protected bool $lastHitFresh = false;

    protected Vector3 $motion;

    protected int $fireTicks = 0;

    protected int $air = self::MAX_AIR;

    protected bool $breathing = true;

    public function __construct(World $world, Vector3 $position){
        parent::__construct($world, $position);
        $this->effects = new EffectManager($this);
        $this->motion = new Vector3(0, 0, 0);
    }

    public function tick(int $currentTick) : void{
        $this->tickInvulnerability();
        $this->tickFire();
        $this->tickAir();
    }

    public function isOnFire() : bool{
        return $this->fireTicks > 0;
    }

    public function getFireTicks() : int{
        return $this->fireTicks;
    }

    /**
     * Sets the entity burning for $seconds, unless it already burns for longer.
     */
    public function setOnFire(int $seconds) : void{
        if($seconds * 20 <= $this->fireTicks){
            return;
        }

        $event = new EntityCombustEvent($this, $seconds);
        $event->call();
        $ticks = $event->getSeconds() * 20;

        if($event->isCancelled() || $ticks <= $this->fireTicks){
            return;
        }

        $wasBurning = $this->isOnFire();
        $this->fireTicks = $ticks;

        if(!$wasBurning){
            $this->onFireChanged();
        }
    }

    public function extinguish() : void{
        if($this->fireTicks > 0){
            $this->fireTicks = 0;
            $this->onFireChanged();
        }
    }

    protected function tickFire() : void{
        if($this->fireTicks <= 0){
            return;
        }

        $position = $this->position;

        if($this->isInWater() || $this->world->isRainingAt($position->getFloorX(), $position->getFloorY(), $position->getFloorZ())){
            $this->extinguish();
            return;
        }

        if($this->fireTicks % 20 === 0){
            $this->damage(new DamageSource(DamageSource::CAUSE_FIRE_TICK, 1.0));
        }

        if(--$this->fireTicks === 0){
            $this->onFireChanged();
        }
    }

    protected function onFireChanged() : void{
    }

    public function isInWater() : bool{
        return false;
    }

    public function isHeadInWater() : bool{
        return false;
    }

    public function getAir() : int{
        return max(0, $this->air);
    }

    public function setAir(int $air) : void{
        $this->air = min($air, self::MAX_AIR);
        $this->onAirChanged();
    }

    public function isBreathing() : bool{
        return $this->breathing && $this->air >= self::MAX_AIR;
    }

    public function canBreatheUnderwater() : bool{
        return $this->effects->has(Effect::WATER_BREATHING) || $this->effects->has(Effect::CONDUIT_POWER);
    }

    protected function tickAir() : void{
        $before = $this->air;
        $bubbles = intdiv($this->getAir() + 29, 30);
        $wasBreathing = $this->breathing;
        $this->breathing = !$this->isHeadInWater() || $this->canBreatheUnderwater();

        if($this->breathing){
            $this->air = min(self::MAX_AIR, max(0, $this->air) + self::AIR_REFILL);
        }else{
            $respiration = $this->getRespirationLevel();

            if($respiration === 0 || mt_rand(0, $respiration) === 0){
                --$this->air;
            }

            if($this->air <= -20){
                $this->air = 0;
                $this->damage(new DamageSource(DamageSource::CAUSE_DROWNING, self::DROWNING_DAMAGE));
            }
        }

        if($wasBreathing !== $this->breathing || $bubbles !== intdiv($this->getAir() + 29, 30) || ($this->air === self::MAX_AIR && $before !== self::MAX_AIR)){
            $this->onAirChanged();
        }
    }

    protected function getRespirationLevel() : int{
        return 0;
    }

    protected function onAirChanged() : void{

    }

    protected function tickInvulnerability() : void{
        if($this->invulnerableTicks > 0){
            --$this->invulnerableTicks;
        }
    }

    public function isInvulnerable() : bool{
        return $this->invulnerableTicks > 0;
    }

    public function getInvulnerableTicks() : int{
        return $this->invulnerableTicks;
    }

    public function wasLastHitFresh() : bool{
        return $this->lastHitFresh;
    }

    public function isUndead() : bool{
        return false;
    }

    public function isArthropod() : bool{
        return false;
    }

    /**
     * The undead shrug off regeneration and poison.
     */
    public function canBeAffected(Effect $effect) : bool{
        return !$this->isUndead() || ($effect !== Effect::REGENERATION && $effect !== Effect::POISON);
    }

    public function getKnockbackResistance() : float{
        return 0.0;
    }

    public function getMotion() : Vector3{
        return $this->motion;
    }

    public function setMotion(Vector3 $motion) : void{
        $this->motion = $motion;
    }

    public function knockBack(float $x, float $z, float $force = self::KNOCKBACK, float $vertical = self::KNOCKBACK, float $extra = 0.0, float $yaw = 0.0) : void{
        $length = sqrt($x * $x + $z * $z);
        $resistance = min(1.0, $this->getKnockbackResistance());

        if($length < 0.0001 || $resistance >= 1.0){
            return;
        }

        $force *= 1 - $resistance;
        $vertical *= 1 - $resistance;
        $extra *= 1 - $resistance;

        $motionX = $x / $length * $force;
        $motionZ = $z / $length * $force;

        if($extra > 0){
            $radians = deg2rad($yaw);
            $motionX = $motionX / 2 - sin($radians) * $extra;
            $motionZ = $motionZ / 2 + cos($radians) * $extra;
        }

        $this->setMotion(new Vector3($motionX, $vertical, $motionZ));
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
     * @param DamageSource $source
     * @return bool
     */
    public function damage(DamageSource $source) : bool{
        if(!$this->isAlive() || $source->getAmount() <= 0 || !$this->canBeDamagedBy($source)){
            return false;
        }

        $event = new EntityDamageEvent($this, $source);
        $event->call();

        if($event->isCancelled() || $source->getAmount() <= 0){
            return false;
        }

        $amount = $this->reduceDamage($source);

        if($amount <= 0){
            return false;
        }

        $fresh = true;

        if($this->invulnerableTicks > 0 && !$source->bypassesGameMode()){
            if($amount <= $this->lastDamage){
                return false;
            }

            $taken = $amount - $this->lastDamage;
            $this->lastDamage = $amount;
            $amount = $taken;
            $fresh = false;
        }else{
            $this->lastDamage = $amount;
            $this->invulnerableTicks = self::INVULNERABLE_TICKS;
        }

        $absorbed = min($amount, $this->absorption);
        $this->absorption -= $absorbed;

        $this->lastDamageSource = $source;
        $this->lastHitFresh = $fresh;
        $this->onHurt($source);
        $this->releaseInfestation();

        if($fresh){
            $this->onDamaged($source);
        }

        $this->setHealth($this->health - ($amount - $absorbed));

        return true;
    }

    /**
     * Adds health, up to the maximum. Does nothing to a dead entity.
     */
    public function heal(float $amount) : void{
        if(!$this->isAlive() || $amount <= 0){
            return;
        }

        if(EntityRegainHealthEvent::hasHandlers()){
            $event = new EntityRegainHealthEvent($this, $amount);
            $event->call();
            $amount = $event->getAmount();

            if($event->isCancelled() || $amount <= 0){
                return;
            }
        }

        $this->setHealth($this->health + $amount);
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

    protected function reduceDamage(DamageSource $source) : float{
        $amount = $source->getAmount();

        if($source->bypassesGameMode()){
            return $amount;
        }

        if($source->isReducedByArmor()){
            [$defense, $toughness] = $this->getArmorValues();

            if($defense > 0){
                $points = max($defense / 5, $defense - 4 * $amount / ($toughness + 8));
                $amount *= 1 - min(20.0, $points) / 25;
            }
        }

        $resistance = $this->effects->getLevel(Effect::RESISTANCE);

        if($resistance > 0){
            $amount *= max(0.0, 1.0 - 0.2 * $resistance);
        }

        $protection = min(20, $this->getProtectionPoints($source));

        if($protection > 0){
            $amount *= 1 - $protection / 25;
        }

        return max(0.0, $amount);
    }

    /**
     * @return array{int, float}
     */
    protected function getArmorValues() : array{
        return [0, 0.0];
    }

    protected function getProtectionPoints(DamageSource $source) : int{
        return 0;
    }

    protected function onHurt(DamageSource $source) : void{

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

    private function releaseInfestation() : void{
        if(!$this->effects->has(Effect::INFESTED) || mt_rand() / mt_getrandmax() > self::INFESTED_CHANCE){
            return;
        }

        for($i = mt_rand(1, 2); $i > 0; --$i){
            new Silverfish($this->world, $this->position->add(0, $this->getHeight() / 2, 0), mt_rand(0, 359));
        }
    }

    protected function onDeath() : void{

    }

    protected function onAttributesChanged() : void{

    }
}