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


    /**
     * Language key of the mode's name, e.g. "gamemode.creative".
     */
    public function getTranslationKey() : string{
        return "gamemode." . $this->getName();
    }

    public function allowsFlying() : bool{
        return $this === self::CREATIVE || $this === self::SPECTATOR;
    }

    /**
     * Spectators always fly and cannot land.
     */
    public function forcesFlying() : bool{
        return $this === self::SPECTATOR;
    }

    public function hasCollision() : bool{
        return $this !== self::SPECTATOR;
    }

    public function isInvulnerable() : bool{
        return $this === self::CREATIVE || $this === self::SPECTATOR;
    }

    /**
     * Blocks break instantly and items are not used up.
     */
    public function hasInstantBuild() : bool{
        return $this === self::CREATIVE;
    }

    /**
     * Placing and breaking blocks,
     * adventure and spectator cannot.
     */
    public function allowsBuilding() : bool{
        return $this === self::SURVIVAL || $this === self::CREATIVE;
    }

    /**
     * Using doors, opening containers and attacking, spectators cannot.
     */
    public function allowsInteraction() : bool{
        return $this !== self::SPECTATOR;
    }

    /**
     * Seen by players who are not spectators themselves.
     */
    public function isVisible() : bool{
        return $this !== self::SPECTATOR;
    }
}