<?php

declare(strict_types=1);

namespace raspberry\player;

use function strtolower;
use function trim;

enum GameMode : int{

    case SURVIVAL = 0;
    case CREATIVE = 1;
    case ADVENTURE = 2;
    case SPECTATOR = 6;

    /**
     * Accepts names ("creative") and numbers ("1"), as used in server.properties.
     */
    public static function fromString(string $value) : ?self{
        return match(strtolower(trim($value))){
            "survival", "s", "0" => self::SURVIVAL,
            "creative", "c", "1" => self::CREATIVE,
            "adventure", "a", "2" => self::ADVENTURE,
            "spectator", "sp", "6" => self::SPECTATOR,
            default => null
        };
    }

    public function getName() : string{
        return strtolower($this->name);
    }
}