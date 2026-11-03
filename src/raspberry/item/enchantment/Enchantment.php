<?php

declare(strict_types=1);

namespace raspberry\item\enchantment;

use function strtolower;
use function str_starts_with;
use function substr;

enum Enchantment : int{

    //plugins in the future should use negative ints for custom enchants
    case CUSTOM = -1;

    case PROTECTION = 0;
    case FIRE_PROTECTION = 1;
    case FEATHER_FALLING = 2;
    case BLAST_PROTECTION = 3;
    case PROJECTILE_PROTECTION = 4;
    case THORNS = 5;
    case RESPIRATION = 6;
    case DEPTH_STRIDER = 7;
    case AQUA_AFFINITY = 8;
    case SHARPNESS = 9;
    case SMITE = 10;
    case BANE_OF_ARTHROPODS = 11;
    case KNOCKBACK = 12;
    case FIRE_ASPECT = 13;
    case LOOTING = 14;
    case EFFICIENCY = 15;
    case SILK_TOUCH = 16;
    case UNBREAKING = 17;
    case FORTUNE = 18;
    case POWER = 19;
    case PUNCH = 20;
    case FLAME = 21;
    case INFINITY = 22;
    case LUCK_OF_THE_SEA = 23;
    case LURE = 24;
    case FROST_WALKER = 25;
    case MENDING = 26;
    case BINDING = 27;
    case VANISHING = 28;
    case IMPALING = 29;
    case RIPTIDE = 30;
    case LOYALTY = 31;
    case CHANNELING = 32;
    case MULTISHOT = 33;
    case PIERCING = 34;
    case QUICK_CHARGE = 35;
    case SOUL_SPEED = 36;
    case SWIFT_SNEAK = 37;
    case WIND_BURST = 38;
    case DENSITY = 39;
    case BREACH = 40;
    case LUNGE = 41;

    /**
     * Accepts the vanilla name with or without "minecraft:" ("sharpness", "minecraft:bow_infinity").
     */
    public static function fromName(string $name) : ?self{
        $name = strtolower($name);

        if(str_starts_with($name, "minecraft:")){
            $name = substr($name, 10);
        }

        foreach(self::cases() as $enchantment){
            if($enchantment->getShortName() === $name){
                return $enchantment;
            }
        }

        return null;
    }

    /**
     * The vanilla name, e.g. "minecraft:sharpness".
     */
    public function getName() : string{
        return "minecraft:" . $this->getShortName();
    }

    /**
     * The name without "minecraft:", as typed in /enchant.
     */
    public function getShortName() : string{
        return match($this){
            self::INFINITY => "bow_infinity",
            default => strtolower($this->name)
        };
    }

    /**
     * The highest level found in survival (enchanting table, anvil, loot). Commands can go higher.
     */
    public function getMaxLevel() : int{
        return match($this){
            self::AQUA_AFFINITY, self::SILK_TOUCH, self::FLAME, self::INFINITY, self::MENDING,
            self::BINDING, self::VANISHING, self::CHANNELING, self::MULTISHOT => 1,
            self::KNOCKBACK, self::FIRE_ASPECT, self::PUNCH, self::FROST_WALKER => 2,
            self::THORNS, self::RESPIRATION, self::DEPTH_STRIDER, self::LOOTING, self::UNBREAKING,
            self::FORTUNE, self::LUCK_OF_THE_SEA, self::LURE, self::RIPTIDE, self::LOYALTY,
            self::QUICK_CHARGE, self::SOUL_SPEED, self::SWIFT_SNEAK, self::WIND_BURST, self::LUNGE => 3,
            self::PROTECTION, self::FIRE_PROTECTION, self::FEATHER_FALLING, self::BLAST_PROTECTION,
            self::PROJECTILE_PROTECTION, self::PIERCING, self::BREACH => 4,
            self::SHARPNESS, self::SMITE, self::BANE_OF_ARTHROPODS, self::EFFICIENCY, self::POWER,
            self::IMPALING, self::DENSITY => 5
        };
    }

    /**
     * Curses (binding, vanishing) cannot be removed with a grindstone.
     */
    public function isCurse() : bool{
        return $this === self::BINDING || $this === self::VANISHING;
    }
}