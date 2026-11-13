<?php

declare(strict_types=1);

namespace raspberry\entity\ai;

use function pack;

class Behavior{

    private const FLOAT = 1;
    private const PANIC = 2;
    private const BREED = 3;
    private const TEMPT = 4;
    private const FOLLOW_PARENT = 5;
    private const RANDOM_STROLL = 6;
    private const LOOK_AT_PLAYER = 7;
    private const RANDOM_LOOK_AROUND = 8;
    private const EAT_BLOCK = 9;

    private int $type;

    private int $priority;

    /** @var array{float, float, float, float} */
    private array $params;

    private function __construct(int $type, int $priority, float $first = 0.0, float $second = 0.0, float $third = 0.0, float $fourth = 0.0){
        $this->type = $type;
        $this->priority = $priority;
        $this->params = [$first, $second, $third, $fourth];
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

    public function encode() : string{
        return pack("CCg4", $this->type, $this->priority, ...$this->params);
    }
}