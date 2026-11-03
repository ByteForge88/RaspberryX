<?php

declare(strict_types=1);

namespace raspberry\item;

use function preg_match;
use function str_ends_with;

class ItemProperties{

    /** @var array<string, int> tool and weapon durability per material */
    private const TIER_DURABILITY = [
        "wooden" => 59,
        "stone" => 131,
        "copper" => 190,
        "iron" => 250,
        "golden" => 32,
        "diamond" => 1561,
        "netherite" => 2031
    ];

    /** @var array<string, array{int, int, int, int}> armor durability per material: helmet, chestplate, leggings, boots */
    private const ARMOR_DURABILITY = [
        "leather" => [55, 80, 75, 65],
        "chainmail" => [165, 240, 225, 195],
        "copper" => [121, 176, 165, 143],
        "iron" => [165, 240, 225, 195],
        "golden" => [77, 112, 105, 91],
        "diamond" => [363, 528, 495, 429],
        "netherite" => [407, 592, 555, 481]
    ];

    /** @var array<string, int> */
    private const DURABILITY = [
        ItemIds::BOW => 384,
        ItemIds::CROSSBOW => 465,
        ItemIds::TRIDENT => 250,
        ItemIds::MACE => 500,
        ItemIds::SHIELD => 336,
        ItemIds::ELYTRA => 432,
        ItemIds::TURTLE_HELMET => 275,
        ItemIds::WOLF_ARMOR => 64,
        ItemIds::FISHING_ROD => 384,
        ItemIds::SHEARS => 238,
        ItemIds::FLINT_AND_STEEL => 64,
        ItemIds::CARROT_ON_A_STICK => 25,
        ItemIds::WARPED_FUNGUS_ON_A_STICK => 100,
        ItemIds::BRUSH => 64
    ];

    /** @var array<string, true> items that do not stack, besides everything with durability */
    private const UNSTACKABLE = [
        ItemIds::SADDLE => true,
        ItemIds::CAKE => true,
        ItemIds::BED => true,
        ItemIds::POTION => true,
        ItemIds::SPLASH_POTION => true,
        ItemIds::LINGERING_POTION => true,
        ItemIds::MUSHROOM_STEW => true,
        ItemIds::RABBIT_STEW => true,
        ItemIds::BEETROOT_SOUP => true,
        ItemIds::SUSPICIOUS_STEW => true,
        ItemIds::TOTEM_OF_UNDYING => true,
        ItemIds::ENCHANTED_BOOK => true,
        ItemIds::WRITABLE_BOOK => true,
        ItemIds::GOAT_HORN => true,
        ItemIds::SPYGLASS => true,
        ItemIds::BUNDLE => true,
        ItemIds::MINECART => true,
        ItemIds::CHEST_MINECART => true,
        ItemIds::HOPPER_MINECART => true,
        ItemIds::TNT_MINECART => true,
        ItemIds::COMMAND_BLOCK_MINECART => true,
        ItemIds::LAVA_BUCKET => true,
        ItemIds::WATER_BUCKET => true,
        ItemIds::MILK_BUCKET => true,
        ItemIds::POWDER_SNOW_BUCKET => true
    ];

    /** @var array<string, true> items that stack to 16 */
    private const STACK_16 = [
        ItemIds::ENDER_PEARL => true,
        ItemIds::SNOWBALL => true,
        ItemIds::EGG => true,
        ItemIds::BLUE_EGG => true,
        ItemIds::BROWN_EGG => true,
        ItemIds::BUCKET => true,
        ItemIds::BANNER => true,
        ItemIds::HONEY_BOTTLE => true,
        ItemIds::ARMOR_STAND => true
    ];

    private function __construct(){
    }

    public static function getMaxStackSize(string $name) : int{
        if(self::getMaxDurability($name) > 0 || isset(self::UNSTACKABLE[$name]) || self::isUnstackableFamily($name)){
            return 1;
        }

        if(isset(self::STACK_16[$name]) || str_ends_with($name, "_sign")){
            return 16;
        }

        return 64;
    }

    public static function getMaxDurability(string $name) : int{
        if(isset(self::DURABILITY[$name])){
            return self::DURABILITY[$name];
        }

        if(preg_match('/^minecraft:([a-z]+)_(sword|shovel|pickaxe|axe|hoe|spear)$/D', $name, $matches) === 1){
            return self::TIER_DURABILITY[$matches[1]] ?? 0;
        }

        if(preg_match('/^minecraft:([a-z]+)_(helmet|chestplate|leggings|boots)$/D', $name, $matches) === 1){
            $durability = self::ARMOR_DURABILITY[$matches[1]] ?? null;

            if($durability !== null){
                return $durability[match($matches[2]){
                    "helmet" => 0,
                    "chestplate" => 1,
                    "leggings" => 2,
                    default => 3
                }];
            }
        }

        return 0;
    }

    private static function isUnstackableFamily(string $name) : bool{
        return preg_match('/^minecraft:(\w+_(boat|chest_boat|shulker_box|bundle|harness|banner_pattern|horse_armor|nautilus_armor)|music_disc_\w+|(axolotl|cod|pufferfish|salmon|tadpole|tropical_fish|sulfur_cube)_bucket)$/D', $name) === 1;
    }
}