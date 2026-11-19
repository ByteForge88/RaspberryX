<?php

declare(strict_types=1);

namespace raspberry\item;

use function preg_match;

class ArmorInfo{

    /** @var array<string, array{int, int, int, int}> */
    private const array DEFENSE = [
        "leather" => [1, 3, 2, 1],
        "copper" => [2, 4, 3, 1],
        "golden" => [2, 5, 3, 1],
        "chainmail" => [2, 5, 4, 1],
        "iron" => [2, 6, 5, 2],
        "diamond" => [3, 8, 6, 3],
        "netherite" => [3, 8, 6, 3]
    ];

    /** @var array<string, float> */
    private const array TOUGHNESS = [
        "diamond" => 2.0,
        "netherite" => 3.0
    ];

    private const array PIECES = ["helmet" => 0, "chestplate" => 1, "leggings" => 2, "boots" => 3];

    private function __construct(){
    }

    public static function getDefense(string $id) : int{
        if($id === ItemIds::TURTLE_HELMET){
            return 2;
        }

        $parts = self::parse($id);
        return $parts === null ? 0 : self::DEFENSE[$parts[0]][self::PIECES[$parts[1]]];
    }

    public static function getToughness(string $id) : float{
        $parts = self::parse($id);
        return $parts === null ? 0.0 : self::TOUGHNESS[$parts[0]] ?? 0.0;
    }

    public static function getKnockbackResistance(string $id) : float{
        $parts = self::parse($id);
        return $parts !== null && $parts[0] === "netherite" ? 0.1 : 0.0;
    }

    /**
     * @return array{string, string}|null
     */
    private static function parse(string $id) : ?array{
        if(preg_match('/^minecraft:([a-z]+)_(helmet|chestplate|leggings|boots)$/D', $id, $matches) !== 1 || !isset(self::DEFENSE[$matches[1]])){
            return null;
        }

        return [$matches[1], $matches[2]];
    }
}