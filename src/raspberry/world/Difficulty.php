<?php

declare(strict_types=1);

namespace raspberry\world;

use function strtolower;
use function trim;

enum Difficulty : int{

    case PEACEFUL = 0;
    case EASY = 1;
    case NORMAL = 2;
    case HARD = 3;

    public static function fromString(string $value) : ?self{
        return match(strtolower(trim($value))){
            "peaceful", "p", "0" => self::PEACEFUL,
            "easy", "e", "1" => self::EASY,
            "normal", "n", "2" => self::NORMAL,
            "hard", "h", "3" => self::HARD,
            default => null
        };
    }

    public function getName() : string{
        return strtolower($this->name);
    }

    /**
     * Starvation only hurts players with more health than this: it stops at 10 on easy and 1 on
     * normal, and kills on hard. Peaceful never starves (food refills by itself).
     */
    public function getStarvationHealthLimit() : float{
        return match($this){
            self::PEACEFUL => 20.0,
            self::EASY => 10.0,
            self::NORMAL => 1.0,
            self::HARD => 0.0
        };
    }

    /**
     * On peaceful, food and health refill by themselves.
     */
    public function refillsFood() : bool{
        return $this === self::PEACEFUL;
    }
}