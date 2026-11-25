<?php

declare(strict_types=1);

namespace raspberry\item;

use function floor;
use function preg_match;

class ToolInfo{

    /** @var array<string, array{int, float}> */
    private const array MATERIALS = [
        "wooden" => [1, 2.0],
        "golden" => [1, 12.0],
        "stone" => [2, 4.0],
        "copper" => [2, 5.0],
        "iron" => [3, 6.0],
        "diamond" => [4, 8.0],
        "netherite" => [5, 9.0]
    ];

    /** @var array<string, float> */
    private const array SWORD_DAMAGE = [
        "wooden" => 5.0,
        "golden" => 5.0,
        "stone" => 6.0,
        "copper" => 6.0,
        "iron" => 7.0,
        "diamond" => 8.0,
        "netherite" => 9.0
    ];

    /** @var array<string, float> */
    private const array TOOL_PENALTY = [
        "sword" => 0.0,
        "axe" => 1.0,
        "pickaxe" => 3.0,
        "shovel" => 3.0,
        "hoe" => 3.0
    ];

    public const float HAND_DAMAGE = 1.0;

    private function __construct(){
    }

    /**
     * How much a hit with $id does before armor and effects, the Bedrock numbers: a fist does
     * 1, a diamond sword 8.
     */
    public static function getAttackDamage(string $id) : float{
        if($id === ItemIds::TRIDENT){
            return 9.0;
        }

        if($id === ItemIds::MACE){
            return 6.0;
        }

        if(preg_match('/^minecraft:([a-z]+)_(pickaxe|axe|shovel|hoe|sword)$/D', $id, $matches) === 1 && isset(self::SWORD_DAMAGE[$matches[1]])){
            return self::SWORD_DAMAGE[$matches[1]] - self::TOOL_PENALTY[$matches[2]];
        }

        return self::HAND_DAMAGE;
    }

    /**
     * The mace's falling attack damage, or null when the fall is too short to smash.
     */
    public static function getMaceSmashDamage(float $fallDistance, int $densityLevel = 0) : ?float{
        if($fallDistance < 1.5){
            return null;
        }

        $height = (int) floor($fallDistance);
        $damage = 0;

        for($i = 0; $i <= $height; ++$i){
            $damage += match(true){
                $i < 3 => 4,
                $i < 8 => 2,
                default => 1
            };
        }

        return $damage + $fallDistance * 0.5 * $densityLevel;
    }

    /**
     * Durability a hit costs: weapons wear by 1, tools that are not made for it by 2.
     */
    public static function getAttackWear(string $id) : int{
        return match(self::getType($id)){
            "pickaxe", "axe", "shovel", "hoe" => 2,
            default => 1
        };
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