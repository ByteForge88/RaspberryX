<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\item\Item;
use raspberry\item\enchantment\Enchantment;

use function mt_rand;

class BlockExperience{

    private const array ORES = [
        "minecraft:coal_ore" => [0, 2],
        "minecraft:deepslate_coal_ore" => [0, 2],
        "minecraft:diamond_ore" => [3, 7],
        "minecraft:deepslate_diamond_ore" => [3, 7],
        "minecraft:emerald_ore" => [3, 7],
        "minecraft:deepslate_emerald_ore" => [3, 7],
        "minecraft:lapis_ore" => [2, 5],
        "minecraft:deepslate_lapis_ore" => [2, 5],
        "minecraft:redstone_ore" => [1, 5],
        "minecraft:lit_redstone_ore" => [1, 5],
        "minecraft:deepslate_redstone_ore" => [1, 5],
        "minecraft:lit_deepslate_redstone_ore" => [1, 5],
        "minecraft:quartz_ore" => [2, 5],
        "minecraft:nether_gold_ore" => [0, 1],
        "minecraft:sculk" => [1, 1],
        "minecraft:sculk_sensor" => [5, 5],
        "minecraft:calibrated_sculk_sensor" => [5, 5],
        "minecraft:sculk_shrieker" => [5, 5],
        "minecraft:sculk_catalyst" => [5, 5]
    ];

    private function __construct(){
    }

    public static function get(BlockState $block, Item $tool, bool $harvested) : int{
        if($tool->getEnchantmentLevel(Enchantment::SILK_TOUCH) > 0){
            return 0;
        }

        if($block->getName() === BlockIds::MOB_SPAWNER){
            return 15 + mt_rand(0, 14) + mt_rand(0, 14);
        }

        $range = self::ORES[$block->getName()] ?? null;

        return $range === null || !$harvested ? 0 : mt_rand($range[0], $range[1]);
    }
}