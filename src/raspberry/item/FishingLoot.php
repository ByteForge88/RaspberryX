<?php

declare(strict_types=1);

namespace raspberry\item;

use raspberry\crafting\EnchantingTable;

use function floor;
use function max;
use function min;
use function mt_getrandmax;
use function mt_rand;

class FishingLoot{

    private const array TABLES = [
        "fish" => [85, -1],
        "treasure" => [5, 2],
        "junk" => [10, -2]
    ];

    private const array FISH = [
        [ItemIds::COD, 60],
        [ItemIds::SALMON, 25],
        [ItemIds::TROPICAL_FISH, 2],
        [ItemIds::PUFFERFISH, 13]
    ];

    private const array JUNGLE_FISH = [
        [ItemIds::COD, 60],
        [ItemIds::SALMON, 40]
    ];

    private const array JUNK = [
        [ItemIds::LEATHER_BOOTS, 10],
        [ItemIds::LEATHER, 10],
        [ItemIds::BONE, 10],
        [ItemIds::POTION, 10],
        [ItemIds::STRING, 5],
        [ItemIds::FISHING_ROD, 2],
        [ItemIds::BOWL, 10],
        [ItemIds::STICK, 5],
        [ItemIds::INK_SAC, 1],
        [ItemIds::TRIPWIRE_HOOK, 10],
        [ItemIds::ROTTEN_FLESH, 10]
    ];

    private const array JUNGLE_JUNK = [
        [ItemIds::BAMBOO, 10],
        [ItemIds::COCOA_BEANS, 10]
    ];

    private const array TREASURE = [
        [ItemIds::NAME_TAG, 5],
        [ItemIds::SADDLE, 5],
        [ItemIds::BOW, 5],
        [ItemIds::FISHING_ROD, 5],
        [ItemIds::BOOK, 6],
        [ItemIds::NAUTILUS_SHELL, 5],
        [ItemIds::WATERLILY, 5]
    ];

    private function __construct(){
    }

    public static function roll(int $luck, bool $jungle) : Item{
        $tables = [];

        foreach(self::TABLES as $table => [$weight, $quality]){
            $tables[] = [$table, (int) max(0, floor($weight + $quality * $luck))];
        }

        return match(self::pick($tables)){
            "fish" => new Item(self::pick($jungle ? self::JUNGLE_FISH : self::FISH)),
            "treasure" => self::treasure(self::pick(self::TREASURE)),
            default => self::junk(self::pick($jungle ? [...self::JUNK, ...self::JUNGLE_JUNK] : self::JUNK))
        };
    }

    private static function junk(string $id) : Item{
        return match($id){
            ItemIds::INK_SAC => new Item($id, 10),
            ItemIds::LEATHER_BOOTS, ItemIds::FISHING_ROD => self::damaged(new Item($id), 0.0, 0.9),
            default => new Item($id)
        };
    }

    private static function treasure(string $id) : Item{
        return match($id){
            ItemIds::BOOK => EnchantingTable::enchantWithLevels(new Item($id), 30, true),
            ItemIds::BOW, ItemIds::FISHING_ROD => EnchantingTable::enchantWithLevels(self::damaged(new Item($id), 0.0, 0.25), mt_rand(22, 30), true),
            default => new Item($id)
        };
    }

    private static function damaged(Item $item, float $min, float $max) : Item{
        $left = $min + mt_rand() / mt_getrandmax() * ($max - $min);
        $durability = $item->getMaxDurability();
        return $item->setDamage(min($durability - 1, (int) floor($durability * (1 - $left))));
    }

    /**
     * @param list<array{string, int}> $entries
     */
    private static function pick(array $entries) : string{
        $total = 0;

        foreach($entries as [, $weight]){
            $total += $weight;
        }

        $roll = mt_rand(0, $total - 1);

        foreach($entries as [$entry, $weight]){
            $roll -= $weight;

            if($roll < 0){
                return $entry;
            }
        }

        return $entries[0][0];
    }
}