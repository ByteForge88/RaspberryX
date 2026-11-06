<?php

declare(strict_types=1);

namespace raspberry\entity\effect;

use function str_replace;
use function strtolower;

enum Effect : int{

    case SPEED = 1;
    case SLOWNESS = 2;
    case HASTE = 3;
    case MINING_FATIGUE = 4;
    case STRENGTH = 5;
    case INSTANT_HEALTH = 6;
    case INSTANT_DAMAGE = 7;
    case JUMP_BOOST = 8;
    case NAUSEA = 9;
    case REGENERATION = 10;
    case RESISTANCE = 11;
    case FIRE_RESISTANCE = 12;
    case WATER_BREATHING = 13;
    case INVISIBILITY = 14;
    case BLINDNESS = 15;
    case NIGHT_VISION = 16;
    case HUNGER = 17;
    case WEAKNESS = 18;
    case POISON = 19;
    case WITHER = 20;
    case HEALTH_BOOST = 21;
    case ABSORPTION = 22;
    case SATURATION = 23;
    case LEVITATION = 24;
    case FATAL_POISON = 25;
    case CONDUIT_POWER = 26;
    case SLOW_FALLING = 27;
    case BAD_OMEN = 28;
    case VILLAGE_HERO = 29;
    case DARKNESS = 30;
    case TRIAL_OMEN = 31;
    case WIND_CHARGED = 32;
    case WEAVING = 33;
    case OOZING = 34;
    case INFESTED = 35;
    case RAID_OMEN = 36;

    public static function fromName(string $name) : ?self{
        $name = str_replace("minecraft:", "", strtolower($name));

        foreach(self::cases() as $effect){
            if($effect->getName() === $name){
                return $effect;
            }
        }

        return null;
    }

    /**
     * The vanilla name, like "night_vision".
     */
    public function getName() : string{
        return strtolower($this->name);
    }

    /**
     * The color its particles get, 0xRRGGBB.
     */
    public function getColor() : int{
        return match($this){
            self::SPEED => 0x33ebff,
            self::SLOWNESS => 0x8bafe0,
            self::HASTE => 0xd9c043,
            self::MINING_FATIGUE => 0x4a4217,
            self::STRENGTH => 0xffc700,
            self::INSTANT_HEALTH => 0xf82423,
            self::INSTANT_DAMAGE => 0xa9656a,
            self::JUMP_BOOST => 0xfdff84,
            self::NAUSEA => 0x551d4a,
            self::REGENERATION => 0xcd5cab,
            self::RESISTANCE => 0x9146f0,
            self::FIRE_RESISTANCE => 0xff9900,
            self::WATER_BREATHING => 0x98dac0,
            self::INVISIBILITY => 0xf6f6f6,
            self::BLINDNESS => 0x1f1f23,
            self::NIGHT_VISION => 0xc2ff66,
            self::HUNGER => 0x587653,
            self::WEAKNESS => 0x484d48,
            self::POISON => 0x87a363,
            self::WITHER => 0x736156,
            self::HEALTH_BOOST => 0xf87d23,
            self::ABSORPTION => 0x2552a5,
            self::SATURATION => 0xf82423,
            self::LEVITATION => 0xceffff,
            self::FATAL_POISON => 0x4e9331,
            self::CONDUIT_POWER => 0x1dc2d1,
            self::SLOW_FALLING => 0xf3cfb9,
            self::BAD_OMEN => 0x0b6138,
            self::VILLAGE_HERO => 0x44ff44,
            self::DARKNESS => 0x292721,
            self::TRIAL_OMEN => 0x16a6a6,
            self::WIND_CHARGED => 0xbdc9ff,
            self::WEAVING => 0x78695a,
            self::OOZING => 0x99ffa3,
            self::INFESTED => 0x8c9b8c,
            self::RAID_OMEN => 0xde4058,
        };
    }

    public function isBad() : bool{
        return match($this){
            self::SLOWNESS, self::MINING_FATIGUE, self::INSTANT_DAMAGE, self::NAUSEA, self::BLINDNESS, self::HUNGER, self::WEAKNESS, self::POISON, self::WITHER, self::LEVITATION, self::FATAL_POISON, self::BAD_OMEN, self::DARKNESS, self::TRIAL_OMEN, self::WIND_CHARGED, self::WEAVING, self::OOZING, self::INFESTED, self::RAID_OMEN => true,
            default => false
        };
    }

    /**
     * Instant effects do their thing once instead of lasting.
     */
    public function isInstant() : bool{
        return $this === self::INSTANT_HEALTH || $this === self::INSTANT_DAMAGE;
    }
}