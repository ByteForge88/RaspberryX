<?php

declare(strict_types=1);

namespace raspberry\item;

use function preg_match;

class ToolInfo{

    /** @var array<string, array{int, float}> */
    private const MATERIALS = [
        "wooden" => [1, 2.0],
        "golden" => [1, 12.0],
        "stone" => [2, 4.0],
        "copper" => [2, 5.0],
        "iron" => [3, 6.0],
        "diamond" => [4, 8.0],
        "netherite" => [5, 9.0]
    ];

    private function __construct(){
    }

    /**
     * "pickaxe", "axe", "shovel", "hoe", "sword" or "shears", null for anything else.
     */
    public static function getType(string $id) : ?string{
        if($id === ItemIds::SHEARS){
            return "shears";
        }

        return preg_match('/^minecraft:([a-z]+)_(pickaxe|axe|shovel|hoe|sword)$/D', $id, $matches) === 1 && isset(self::MATERIALS[$matches[1]]) ? $matches[2] : null;
    }

    /**
     * 0 for items that are not tiered tools, 1 (wood, gold) up to 5 (netherite).
     */
    public static function getTierLevel(string $id) : int{
        return preg_match('/^minecraft:([a-z]+)_(pickaxe|axe|shovel|hoe|sword)$/D', $id, $matches) === 1 ? (self::MATERIALS[$matches[1]][0] ?? 0) : 0;
    }

    /**
     * How fast $id breaks a block that needs $blockTool (1 for a hand or the wrong tool).
     */
    public static function getSpeed(string $id, ?string $blockTool, string $blockName) : float{
        $type = self::getType($id);

        if($type === "shears"){
            return match(true){
                $blockName === "minecraft:web" || $blockTool === "hoe" && preg_match('/leaves/', $blockName) === 1 => 15.0,
                $blockTool === "shears" => 5.0,
                default => 1.0
            };
        }

        if($type === "sword"){
            return $blockName === "minecraft:web" ? 15.0 : 1.5;
        }

        if($type === null || $type !== $blockTool){
            return 1.0;
        }

        preg_match('/^minecraft:([a-z]+)_/', $id, $matches);

        return self::MATERIALS[$matches[1]][1];
    }
}