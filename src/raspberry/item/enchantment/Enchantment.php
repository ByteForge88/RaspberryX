<?php

declare(strict_types=1);

namespace raspberry\item\enchantment;

use raspberry\item\Item;
use raspberry\item\ItemIds;
use raspberry\item\ItemProperties;

use function in_array;
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

    /**
     * Never offered by the enchanting table, only found as loot or traded.
     */
    public function isTreasure() : bool{
        return match($this){
            self::CUSTOM, self::FROST_WALKER, self::MENDING, self::BINDING, self::VANISHING,
            self::SOUL_SPEED, self::SWIFT_SNEAK, self::WIND_BURST => true,
            default => false
        };
    }

    /**
     * How likely the enchanting table picks it: 10 common, 5 uncommon, 2 rare, 1 very rare.
     */
    public function getWeight() : int{
        return match($this){
            self::PROTECTION, self::SHARPNESS, self::EFFICIENCY, self::POWER, self::PIERCING => 10,
            self::FIRE_PROTECTION, self::FEATHER_FALLING, self::PROJECTILE_PROTECTION, self::SMITE,
            self::BANE_OF_ARTHROPODS, self::KNOCKBACK, self::UNBREAKING, self::LOYALTY,
            self::QUICK_CHARGE, self::DENSITY, self::LUNGE => 5,
            self::BLAST_PROTECTION, self::RESPIRATION, self::DEPTH_STRIDER, self::AQUA_AFFINITY,
            self::FIRE_ASPECT, self::LOOTING, self::FORTUNE, self::PUNCH, self::FLAME,
            self::LUCK_OF_THE_SEA, self::LURE, self::FROST_WALKER, self::MENDING, self::IMPALING,
            self::RIPTIDE, self::MULTISHOT, self::WIND_BURST, self::BREACH => 2,
            default => 1
        };
    }

    /**
     * Levels an anvil charges for each level of it (half when it comes from a book).
     */
    public function getAnvilCost() : int{
        return match($this->getWeight()){
            10 => 1,
            5 => 2,
            2 => 4,
            default => 8
        };
    }

    /**
     * The enchanting power that gives $level, lowest and highest.
     *
     * @return array{int, int}
     */
    public function getPowerRange(int $level) : array{
        $min = match($this){
            self::PROTECTION, self::SHARPNESS => 1 + ($level - 1) * 11,
            self::FIRE_PROTECTION => 10 + ($level - 1) * 8,
            self::FEATHER_FALLING => 5 + ($level - 1) * 6,
            self::BLAST_PROTECTION, self::SMITE, self::BANE_OF_ARTHROPODS, self::UNBREAKING, self::DENSITY, self::LUNGE => 5 + ($level - 1) * 8,
            self::PROJECTILE_PROTECTION => 3 + ($level - 1) * 6,
            self::THORNS, self::FIRE_ASPECT => 10 + ($level - 1) * 20,
            self::RESPIRATION, self::DEPTH_STRIDER, self::FROST_WALKER, self::SOUL_SPEED => 10 * $level,
            self::KNOCKBACK => 5 + ($level - 1) * 20,
            self::LOOTING, self::FORTUNE, self::LUCK_OF_THE_SEA, self::LURE, self::BREACH, self::WIND_BURST => 15 + ($level - 1) * 9,
            self::EFFICIENCY, self::POWER, self::PIERCING => 1 + ($level - 1) * 10,
            self::SILK_TOUCH => 15,
            self::PUNCH, self::QUICK_CHARGE => 12 + ($level - 1) * 20,
            self::FLAME, self::INFINITY, self::MULTISHOT => 20,
            self::IMPALING => 1 + ($level - 1) * 8,
            self::RIPTIDE => 10 + $level * 7,
            self::LOYALTY => 5 + $level * 7,
            self::MENDING, self::BINDING, self::VANISHING, self::CHANNELING => 25,
            self::SWIFT_SNEAK => 25 * $level,
            self::AQUA_AFFINITY, self::CUSTOM => 1
        };

        $max = match($this){
            self::PROTECTION => $min + 11,
            self::FIRE_PROTECTION, self::BLAST_PROTECTION => $min + 8,
            self::FEATHER_FALLING, self::PROJECTILE_PROTECTION => $min + 6,
            self::RESPIRATION => $min + 30,
            self::DEPTH_STRIDER, self::FROST_WALKER, self::SOUL_SPEED, self::POWER => $min + 15,
            self::AQUA_AFFINITY => $min + 40,
            self::SHARPNESS, self::SMITE, self::BANE_OF_ARTHROPODS, self::IMPALING, self::DENSITY, self::LUNGE => $min + 20,
            self::PUNCH => $min + 25,
            self::FLAME, self::INFINITY, self::RIPTIDE, self::LOYALTY, self::CHANNELING, self::MULTISHOT,
            self::PIERCING, self::QUICK_CHARGE, self::BINDING, self::VANISHING => 50,
            default => $min + 50
        };

        return [$min, $max];
    }

    /**
     * Whether both can be on one item: protections, damage enchantments and a few pairs exclude
     * each other.
     */
    public function isCompatibleWith(self $other) : bool{
        if($other === $this){
            return true;
        }

        $groups = [
            [self::PROTECTION, self::FIRE_PROTECTION, self::BLAST_PROTECTION, self::PROJECTILE_PROTECTION],
            [self::SHARPNESS, self::SMITE, self::BANE_OF_ARTHROPODS, self::DENSITY, self::BREACH],
            [self::SILK_TOUCH, self::FORTUNE],
            [self::INFINITY, self::MENDING],
            [self::MULTISHOT, self::PIERCING],
            [self::DEPTH_STRIDER, self::FROST_WALKER],
            [self::RIPTIDE, self::LOYALTY],
            [self::RIPTIDE, self::CHANNELING]
        ];

        foreach($groups as $group){
            if(in_array($this, $group, true) && in_array($other, $group, true)){
                return false;
            }
        }

        return true;
    }

    /**
     * Whether it can go on $item. The enchanting table is stricter than the anvil (thorns only
     * on chestplates, efficiency not on shears...).
     */
    public function canApplyTo(Item $item, bool $anvil) : bool{
        $kind = ItemProperties::getEnchantKind($item->getId());

        if($kind === "book"){
            return true;
        }

        $armor = ["helmet", "chestplate", "leggings", "boots"];

        $kinds = match($this){
            self::PROTECTION, self::FIRE_PROTECTION, self::BLAST_PROTECTION, self::PROJECTILE_PROTECTION => $armor,
            self::THORNS => $anvil ? $armor : ["chestplate"],
            self::RESPIRATION, self::AQUA_AFFINITY => ["helmet"],
            self::FEATHER_FALLING, self::DEPTH_STRIDER, self::FROST_WALKER, self::SOUL_SPEED => ["boots"],
            self::SWIFT_SNEAK => ["leggings"],
            self::SHARPNESS => ["sword", "axe", "spear"],
            self::SMITE, self::BANE_OF_ARTHROPODS => ["sword", "axe", "spear", "mace"],
            self::KNOCKBACK, self::LOOTING => ["sword", "spear"],
            self::FIRE_ASPECT => ["sword", "spear", "mace"],
            self::EFFICIENCY => $anvil ? ["pickaxe", "shovel", "axe", "hoe", "shears"] : ["pickaxe", "shovel", "axe", "hoe"],
            self::SILK_TOUCH, self::FORTUNE => ["pickaxe", "shovel", "axe", "hoe"],
            self::POWER, self::PUNCH, self::FLAME, self::INFINITY => ["bow"],
            self::LUCK_OF_THE_SEA, self::LURE => ["fishing_rod"],
            self::IMPALING, self::RIPTIDE, self::LOYALTY, self::CHANNELING => ["trident"],
            self::MULTISHOT, self::PIERCING, self::QUICK_CHARGE => ["crossbow"],
            self::DENSITY, self::BREACH, self::WIND_BURST => ["mace"],
            self::LUNGE => ["spear"],
            self::BINDING => [...$armor, "elytra", "head"],
            self::UNBREAKING, self::MENDING, self::VANISHING, self::CUSTOM => []
        };

        if(in_array($kind, $kinds, true)){
            return true;
        }

        return match($this){
            self::UNBREAKING => $item->hasDurability() && ($anvil || $kind !== null),
            self::MENDING => $item->hasDurability(),
            self::VANISHING => $item->hasDurability() || $kind === "head" || $item->getId() === ItemIds::COMPASS,
            default => false
        };
    }
}