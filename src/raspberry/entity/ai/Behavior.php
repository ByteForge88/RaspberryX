<?php

declare(strict_types=1);

namespace raspberry\entity\ai;

use function pack;

class Behavior{

    private const int FLOAT = 1;
    private const int PANIC = 2;
    private const int BREED = 3;
    private const int TEMPT = 4;
    private const int FOLLOW_PARENT = 5;
    private const int RANDOM_STROLL = 6;
    private const int LOOK_AT_PLAYER = 7;
    private const int RANDOM_LOOK_AROUND = 8;
    private const int EAT_BLOCK = 9;
    private const int NEAREST_ATTACKABLE_TARGET = 10;
    private const int HURT_BY_TARGET = 11;
    private const int MELEE_ATTACK = 12;
    private const int LEAP_AT_TARGET = 13;
    private const int SWELL = 14;
    private const int RANGED_BOW_ATTACK = 15;
    private const int RANGED_ATTACK = 16;
    private const int SLIME_ATTACK = 17;
    private const int SLIME_RANDOM_DIRECTION = 18;

    private const float ONLY_IN_DARK = 1.0;
    private const float IN_WATER_BY_DAY = 2.0;

    private int $type;

    private int $priority;

    /** @var array{float, float, float, float, float, float} */
    private array $params;

    private function __construct(int $type, int $priority, float $first = 0.0, float $second = 0.0, float $third = 0.0, float $fourth = 0.0, float $fifth = 0.0, float $sixth = 0.0){
        $this->type = $type;
        $this->priority = $priority;
        $this->params = [$first, $second, $third, $fourth, $fifth, $sixth];
    }

    /** swims up instead of sinking */
    public static function float(int $priority) : self{
        return new self(self::FLOAT, $priority);
    }

    /** runs after being hurt, or while burning */
    public static function panic(int $priority, float $speed) : self{
        return new self(self::PANIC, $priority, $speed);
    }

    /** goes to another of its kind when both are in love */
    public static function breed(int $priority, float $speed) : self{
        return new self(self::BREED, $priority, $speed);
    }

    /**
     * @param list<string> $items
     */
    public static function tempt(int $priority, float $speed, array $items) : self{
        return new self(self::TEMPT, $priority, $speed, (float) TemptItems::group($items));
    }

    /** babies stay near an adult */
    public static function followParent(int $priority, float $speed) : self{
        return new self(self::FOLLOW_PARENT, $priority, $speed);
    }

    /** wanders off now and then, once in $interval ticks on average */
    public static function randomStroll(int $priority, float $speed, int $interval = 120) : self{
        return new self(self::RANDOM_STROLL, $priority, $speed, (float) $interval);
    }

    public static function lookAtPlayer(int $priority, float $distance = 6.0, float $probability = 0.02) : self{
        return new self(self::LOOK_AT_PLAYER, $priority, $distance, $probability);
    }

    public static function randomLookAround(int $priority) : self{
        return new self(self::RANDOM_LOOK_AROUND, $priority);
    }

    /** eats the grass it stands on, the chances are per tick */
    public static function eatBlock(int $priority, float $adultChance = 0.001, float $babyChance = 0.02) : self{
        return new self(self::EAT_BLOCK, $priority, $adultChance, $babyChance);
    }

    /**
     * Goes after the nearest player within $followRange, seen first when $mustSee. Looks once in
     * $interval ticks on average.
     */
    public static function nearestAttackableTarget(int $priority, float $followRange, bool $mustSee = true, int $interval = 10, bool $onlyInDark = false, bool $inWaterByDay = false) : self{
        return new self(self::NEAREST_ATTACKABLE_TARGET, $priority, $followRange, $mustSee ? 1.0 : 0.0, (float) $interval, $onlyInDark ? self::ONLY_IN_DARK : ($inWaterByDay ? self::IN_WATER_BY_DAY : 0.0));
    }

    /** goes after whoever hurt it */
    public static function hurtByTarget(int $priority, float $followRange) : self{
        return new self(self::HURT_BY_TARGET, $priority, $followRange, 1.0);
    }

    /**
     * Walks up to its target and hits it once a second. Without $followUnseen it stops chasing
     * when it loses sight of it.
     */
    public static function meleeAttack(int $priority, float $speed, bool $followUnseen = false, bool $givesUpInLight = false, float $maxRange = 0.0) : self{
        return new self(self::MELEE_ATTACK, $priority, $speed, $followUnseen ? 1.0 : 0.0, $givesUpInLight ? 1.0 : 0.0, $maxRange);
    }

    public static function leapAtTarget(int $priority, float $height) : self{
        return new self(self::LEAP_AT_TARGET, $priority, $height);
    }

    public static function slimeAttack(int $priority) : self{
        return new self(self::SLIME_ATTACK, $priority);
    }

    public static function slimeRandomDirection(int $priority) : self{
        return new self(self::SLIME_RANDOM_DIRECTION, $priority);
    }

    public static function swell(int $priority) : self{
        return new self(self::SWELL, $priority);
    }

    public static function rangedBowAttack(int $priority, float $speed, int $interval, float $radius) : self{
        return new self(self::RANGED_BOW_ATTACK, $priority, $speed, (float) $interval, $radius);
    }

    public static function rangedAttack(int $priority, float $speed, int $minInterval, int $maxInterval, float $radius, float $minRange = 0.0) : self{
        return new self(self::RANGED_ATTACK, $priority, $speed, (float) $minInterval, (float) $maxInterval, $radius, $minRange);
    }

    public function encode() : string{
        return pack("CCg6", $this->type, $this->priority, ...$this->params);
    }
}