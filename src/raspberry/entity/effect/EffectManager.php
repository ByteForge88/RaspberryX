<?php

declare(strict_types=1);

namespace raspberry\entity\effect;

use raspberry\entity\Human;
use raspberry\entity\Living;
use raspberry\entity\damage\DamageSource;

use function max;
use function min;

class EffectManager{

    private Living $entity;

    /** @var array<int, EffectInstance> */
    private array $effects = [];

    public function __construct(Living $entity){
        $this->entity = $entity;
    }

    /**
     * @return array<int, EffectInstance>
     */
    public function all() : array{
        return $this->effects;
    }

    public function get(Effect $effect) : ?EffectInstance{
        return $this->effects[$effect->value] ?? null;
    }

    public function has(Effect $effect) : bool{
        return isset($this->effects[$effect->value]);
    }

    public function getLevel(Effect $effect) : int{
        return isset($this->effects[$effect->value]) ? $this->effects[$effect->value]->getLevel() : 0;
    }

    /**
     * Gives the entity an effect. Like vanilla, a weaker or shorter one than the entity already has
     * does nothing, unless $force. Instant effects are used up right away.
     */
    public function add(EffectInstance $effect, bool $force = false, float $potency = 1.0) : bool{
        $type = $effect->getEffect();

        if($type->isInstant()){
            $this->applyInstant($type, $effect->getAmplifier(), $potency);
            return true;
        }

        $old = $this->effects[$type->value] ?? null;

        if(!$force && $old !== null && ($old->getAmplifier() > $effect->getAmplifier() || ($old->getAmplifier() === $effect->getAmplifier() && $old->getDuration() > $effect->getDuration()))){
            return false;
        }

        $this->effects[$type->value] = $effect;
        $this->entity->onEffectAdded($effect, $old);

        return true;
    }

    public function remove(Effect $effect) : void{
        $old = $this->effects[$effect->value] ?? null;

        if($old !== null){
            unset($this->effects[$effect->value]);
            $this->entity->onEffectRemoved($old);
        }
    }

    public function clear() : void{
        foreach($this->effects as $effect){
            $this->remove($effect->getEffect());
        }
    }

    /**
     * Runs one tick of every effect and takes away the ones that ran out.
     */
    public function tick() : void{
        foreach($this->effects as $effect){
            $this->applyTick($effect);

            if(!isset($this->effects[$effect->getEffect()->value])){
                continue;
            }

            $effect->setDuration($effect->getDuration() - 1);

            if($effect->getDuration() <= 0){
                $this->remove($effect->getEffect());
            }
        }
    }

    /**
     * How much faster the entity breaks blocks: haste speeds it up, mining fatigue slows it right down.
     */
    public function getBreakSpeedMultiplier() : float{
        $multiplier = 1.0 + 0.2 * $this->getLevel(Effect::HASTE);
        $fatigue = $this->getLevel(Effect::MINING_FATIGUE);

        if($fatigue > 0){
            $multiplier *= 0.3 ** min($fatigue, 4);
        }

        return $multiplier;
    }

    private function applyTick(EffectInstance $effect) : void{
        $entity = $this->entity;
        $amplifier = $effect->getAmplifier();
        $duration = $effect->getDuration();

        match($effect->getEffect()){
            Effect::REGENERATION => self::every($duration, 50, $amplifier) && $entity->getHealth() < $entity->getMaxHealth() ? $entity->heal(1.0) : null,
            Effect::POISON => self::every($duration, 25, $amplifier) && $entity->getHealth() > 1.0 ? $entity->damage(new DamageSource(DamageSource::CAUSE_MAGIC, 1.0)) : null,
            Effect::FATAL_POISON => self::every($duration, 25, $amplifier) ? $entity->damage(new DamageSource(DamageSource::CAUSE_MAGIC, 1.0)) : null,
            Effect::WITHER => self::every($duration, 40, $amplifier) ? $entity->damage(new DamageSource(DamageSource::CAUSE_WITHER, 1.0)) : null,
            Effect::HUNGER => $entity instanceof Human ? $entity->exhaust(0.005 * $effect->getLevel()) : null,
            Effect::SATURATION => $entity instanceof Human ? self::feed($entity, $effect->getLevel()) : null,
            default => null
        };
    }

    private function applyInstant(Effect $effect, int $amplifier, float $potency) : void{
        if($effect === Effect::INSTANT_HEALTH){
            $this->entity->heal((4 << min($amplifier, 30)) * $potency);
        }elseif($effect === Effect::INSTANT_DAMAGE){
            $this->entity->damage(new DamageSource(DamageSource::CAUSE_MAGIC, (6 << min($amplifier, 30)) * $potency));
        }
    }

    /**
     * Regeneration, poison and wither work every $interval ticks, halved for each level above the first.
     */
    private static function every(int $duration, int $interval, int $amplifier) : bool{
        $interval = max(1, $interval >> min($amplifier, 30));

        return $duration % $interval === 0;
    }

    private static function feed(Human $human, int $level) : void{
        $human->setFood(min($human->getFood() + $level, Human::MAX_FOOD));
        $human->setSaturation(min($human->getSaturation() + 2.0 * $level, (float) $human->getFood()));
    }
}