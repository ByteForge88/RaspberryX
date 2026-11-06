<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\nbt\tag\CompoundTag;

use function preg_match;

class BlockEntityFactory{

    private function __construct(){
    }

    /**
     * A new block entity for the block $blockName at a position, null when the block has none.
     */
    public static function create(string $blockName, int $x, int $y, int $z) : ?BlockEntity{
        $id = self::getSaveId($blockName);

        return $id === null ? null : self::make($id, $x, $y, $z);
    }

    /**
     * The block entity "id" a block uses, null when it has none.
     */
    public static function getSaveId(string $blockName) : ?string{
        return match(true){
            preg_match('/^minecraft:\w+_hanging_sign$/D', $blockName) === 1 => Sign::ID_HANGING_SIGN,
            preg_match('/^minecraft:(\w+_)?(standing|wall)_sign$/D', $blockName) === 1 => Sign::ID_SIGN,
            $blockName === "minecraft:bed" => "Bed",
            preg_match('/^minecraft:(standing|wall)_banner$/D', $blockName) === 1 => "Banner",
            preg_match('/^minecraft:(skeleton_skull|wither_skeleton_skull|zombie_head|player_head|creeper_head|dragon_head|piglin_head)$/D', $blockName) === 1 => "Skull",
            $blockName === "minecraft:chest" || $blockName === "minecraft:trapped_chest" => "Chest",
            preg_match('/^minecraft:(waxed_)?((exposed|weathered|oxidized)_)?copper_chest$/D', $blockName) === 1 => "Chest",
            $blockName === "minecraft:barrel" => "Barrel",
            preg_match('/^minecraft:\w+_shulker_box$/D', $blockName) === 1 => "ShulkerBox",
            $blockName === "minecraft:ender_chest" => "EnderChest",
            $blockName === "minecraft:furnace" || $blockName === "minecraft:lit_furnace" => Furnace::ID_FURNACE,
            $blockName === "minecraft:blast_furnace" || $blockName === "minecraft:lit_blast_furnace" => Furnace::ID_BLAST_FURNACE,
            $blockName === "minecraft:smoker" || $blockName === "minecraft:lit_smoker" => Furnace::ID_SMOKER,
            $blockName === "minecraft:brewing_stand" => "BrewingStand",
            $blockName === "minecraft:hopper" => "Hopper",
            $blockName === "minecraft:powered_comparator" || $blockName === "minecraft:unpowered_comparator" => "Comparator",
            $blockName === "minecraft:dispenser" => "Dispenser",
            $blockName === "minecraft:dropper" => "Dropper",
            $blockName === "minecraft:crafter" => "Crafter",
            $blockName === "minecraft:jukebox" => "Jukebox",
            $blockName === "minecraft:lectern" => "Lectern",
            $blockName === "minecraft:flower_pot" => "FlowerPot",
            $blockName === "minecraft:campfire" || $blockName === "minecraft:soul_campfire" => "Campfire",
            $blockName === "minecraft:beacon" => "Beacon",
            $blockName === "minecraft:cauldron" => "Cauldron",
            default => null
        };
    }

    /**
     * A block entity loaded from a chunk, null when its id is unknown.
     */
    public static function load(CompoundTag $nbt) : ?BlockEntity{
        $x = $nbt->getInt("x");
        $y = $nbt->getInt("y");
        $z = $nbt->getInt("z");
        $id = $nbt->getString("id");

        if($x === null || $y === null || $z === null || $id === null){
            return null;
        }

        $entity = self::make($id, $x, $y, $z);
        $entity?->readData($nbt);

        return $entity;
    }

    private static function make(string $id, int $x, int $y, int $z) : ?BlockEntity{
        return match($id){
            Sign::ID_SIGN, Sign::ID_HANGING_SIGN => new Sign($x, $y, $z, $id),
            "Bed" => new Bed($x, $y, $z),
            "Banner" => new Banner($x, $y, $z),
            "Skull" => new Skull($x, $y, $z),
            "Chest" => new Chest($x, $y, $z),
            "Barrel" => new Barrel($x, $y, $z),
            "ShulkerBox" => new ShulkerBox($x, $y, $z),
            "EnderChest" => new EnderChest($x, $y, $z),
            Furnace::ID_FURNACE, Furnace::ID_BLAST_FURNACE, Furnace::ID_SMOKER => new Furnace($x, $y, $z, $id),
            "BrewingStand" => new BrewingStand($x, $y, $z),
            "Hopper" => new Hopper($x, $y, $z),
            "Comparator" => new Comparator($x, $y, $z),
            "Dispenser" => new Dispenser($x, $y, $z),
            "Dropper" => new Dropper($x, $y, $z),
            "Crafter" => new Crafter($x, $y, $z),
            "Jukebox" => new Jukebox($x, $y, $z),
            "Lectern" => new Lectern($x, $y, $z),
            "FlowerPot" => new FlowerPot($x, $y, $z),
            "Campfire" => new Campfire($x, $y, $z),
            "Beacon" => new Beacon($x, $y, $z),
            "Cauldron" => new Cauldron($x, $y, $z),
            default => null
        };
    }
}