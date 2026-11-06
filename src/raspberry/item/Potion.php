<?php

declare(strict_types=1);

namespace raspberry\item;

use raspberry\entity\effect\Effect;
use raspberry\entity\effect\EffectInstance;

use function count;
use function intdiv;

class Potion{

    /** water bottles and the like: the color the client gives potions without effects */
    public const WATER_COLOR = 0x385dc6;

    /** potion meta => effect, duration in ticks, amplifier */
    private const EFFECTS = [
        0 => [], //water
        1 => [], //mundane
        2 => [], //long_mundane
        3 => [], //thick
        4 => [], //awkward
        5 => [[Effect::NIGHT_VISION, 3600, 0]], //nightvision
        6 => [[Effect::NIGHT_VISION, 9600, 0]], //long_nightvision
        7 => [[Effect::INVISIBILITY, 3600, 0]], //invisibility
        8 => [[Effect::INVISIBILITY, 9600, 0]], //long_invisibility
        9 => [[Effect::JUMP_BOOST, 3600, 0]], //leaping
        10 => [[Effect::JUMP_BOOST, 9600, 0]], //long_leaping
        11 => [[Effect::JUMP_BOOST, 1800, 1]], //strong_leaping
        12 => [[Effect::FIRE_RESISTANCE, 3600, 0]], //fire_resistance
        13 => [[Effect::FIRE_RESISTANCE, 9600, 0]], //long_fire_resistance
        14 => [[Effect::SPEED, 3600, 0]], //swiftness
        15 => [[Effect::SPEED, 9600, 0]], //long_swiftness
        16 => [[Effect::SPEED, 1800, 1]], //strong_swiftness
        17 => [[Effect::SLOWNESS, 1800, 0]], //slowness
        18 => [[Effect::SLOWNESS, 4800, 0]], //long_slowness
        19 => [[Effect::WATER_BREATHING, 3600, 0]], //water_breathing
        20 => [[Effect::WATER_BREATHING, 9600, 0]], //long_water_breathing
        21 => [[Effect::INSTANT_HEALTH, 1, 0]], //healing
        22 => [[Effect::INSTANT_HEALTH, 1, 1]], //strong_healing
        23 => [[Effect::INSTANT_DAMAGE, 1, 0]], //harming
        24 => [[Effect::INSTANT_DAMAGE, 1, 1]], //strong_harming
        25 => [[Effect::POISON, 900, 0]], //poison
        26 => [[Effect::POISON, 2400, 0]], //long_poison
        27 => [[Effect::POISON, 450, 1]], //strong_poison
        28 => [[Effect::REGENERATION, 900, 0]], //regeneration
        29 => [[Effect::REGENERATION, 2400, 0]], //long_regeneration
        30 => [[Effect::REGENERATION, 450, 1]], //strong_regeneration
        31 => [[Effect::STRENGTH, 3600, 0]], //strength
        32 => [[Effect::STRENGTH, 9600, 0]], //long_strength
        33 => [[Effect::STRENGTH, 1800, 1]], //strong_strength
        34 => [[Effect::WEAKNESS, 1800, 0]], //weakness
        35 => [[Effect::WEAKNESS, 4800, 0]], //long_weakness
        36 => [[Effect::WITHER, 800, 0]], //wither
        37 => [[Effect::RESISTANCE, 400, 2], [Effect::SLOWNESS, 400, 3]], //turtle_master
        38 => [[Effect::RESISTANCE, 800, 2], [Effect::SLOWNESS, 800, 3]], //long_turtle_master
        39 => [[Effect::RESISTANCE, 400, 4], [Effect::SLOWNESS, 400, 5]], //strong_turtle_master
        40 => [[Effect::SLOW_FALLING, 1800, 0]], //slow_falling
        41 => [[Effect::SLOW_FALLING, 4800, 0]], //long_slow_falling
        42 => [[Effect::SLOWNESS, 400, 3]], //strong_slowness
        43 => [[Effect::WIND_CHARGED, 3600, 0]], //wind_charged
        44 => [[Effect::WEAVING, 3600, 0]], //weaving
        45 => [[Effect::OOZING, 3600, 0]], //oozing
        46 => [[Effect::INFESTED, 3600, 0]], //infested
    ];

    private function __construct(){
    }

    public static function isPotion(Item $item) : bool{
        return $item->getId() === ItemIds::POTION || $item->getId() === ItemIds::SPLASH_POTION || $item->getId() === ItemIds::LINGERING_POTION;
    }

    /**
     * What a potion of this kind (its meta) gives. Splash potions give the same, a lingering cloud a
     * quarter of it.
     *
     * @return list<EffectInstance>
     */
    public static function getEffects(int $meta, int $durationDivider = 1) : array{
        $effects = [];

        foreach(self::EFFECTS[$meta] ?? [] as [$effect, $duration, $amplifier]){
            $effects[] = new EffectInstance($effect, $effect->isInstant() ? 1 : intdiv($duration, $durationDivider), $amplifier);
        }

        return $effects;
    }

    public static function exists(int $meta) : bool{
        return isset(self::EFFECTS[$meta]);
    }

    /**
     * The color of a potion of this kind, 0xRRGGBB.
     */
    public static function getColor(int $meta) : int{
        $effects = [];

        foreach(self::EFFECTS[$meta] ?? [] as [$effect]){
            $effects[] = $effect;
        }

        return $effects === [] ? self::WATER_COLOR : self::mixColors($effects);
    }

    /**
     * @param list<Effect> $effects
     */
    public static function mixColors(array $effects) : int{
        [$r, $g, $b] = [0, 0, 0];

        foreach($effects as $effect){
            $color = $effect->getColor();
            $r += $color >> 16 & 0xff;
            $g += $color >> 8 & 0xff;
            $b += $color & 0xff;
        }

        $count = count($effects);

        return intdiv($r, $count) << 16 | intdiv($g, $count) << 8 | intdiv($b, $count);
    }
}