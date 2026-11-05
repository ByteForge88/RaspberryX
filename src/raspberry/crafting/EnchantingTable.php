<?php

declare(strict_types=1);

namespace raspberry\crafting;

use raspberry\block\BlockIds;

use raspberry\item\Item;
use raspberry\item\ItemIds;
use raspberry\item\ItemProperties;
use raspberry\item\enchantment\Enchantment;

use raspberry\world\Chunk;
use raspberry\world\World;

use Random\Engine\Mt19937;
use Random\Randomizer;

use function array_filter;
use function array_values;
use function count;
use function intdiv;
use function max;
use function round;

class EnchantingTable{

    public const MAX_BOOKSHELVES = 15;

    /** shown on the buttons in the enchanting script */
    private const WORDS = [
        "the", "elder", "scrolls", "klaatu", "berata", "niktu", "xyzzy", "bless", "curse", "light",
        "darkness", "fire", "air", "earth", "water", "hot", "dry", "cold", "wet", "ignite", "snuff",
        "embiggen", "twist", "shorten", "stretch", "fiddle", "destroy", "imbue", "galvanize",
        "enchant", "free", "limited", "range", "of", "towards", "inside", "sphere", "cube", "self",
        "other", "ball", "mental", "physical", "grow", "shrink", "demon", "elemental", "spirit",
        "animal", "creature", "beast", "humanoid", "undead", "fresh", "stale"
    ];

    private function __construct(){
    }

    /**
     * Bookshelves one block away from the table, with air in between, at most 15.
     */
    public static function countBookshelves(World $world, int $x, int $y, int $z) : int{
        $count = 0;

        for($dx = -1; $dx <= 1; ++$dx){
            for($dz = -1; $dz <= 1; ++$dz){
                if($dx === 0 && $dz === 0){
                    continue;
                }

                for($dy = 0; $dy <= 1; ++$dy){
                    if(self::getBlockName($world, $x + $dx, $y + $dy, $z + $dz) !== BlockIds::AIR){
                        continue;
                    }

                    $count += self::isBookshelf($world, $x + $dx * 2, $y + $dy, $z + $dz * 2) ? 1 : 0;

                    if($dx !== 0 && $dz !== 0){
                        $count += self::isBookshelf($world, $x + $dx * 2, $y + $dy, $z + $dz) ? 1 : 0;
                        $count += self::isBookshelf($world, $x + $dx, $y + $dy, $z + $dz * 2) ? 1 : 0;
                    }

                    if($count >= self::MAX_BOOKSHELVES){
                        return self::MAX_BOOKSHELVES;
                    }
                }
            }
        }

        return $count;
    }

    /**
     * @return array<int, array{int, list<array{Enchantment, int}>}>
     */
    public static function getOffers(Item $item, int $bookshelves, int $seed) : array{
        $value = ItemProperties::getEnchantability($item->getId());

        if($value === 0 || $item->getCount() !== 1 || $item->hasEnchantments()){
            return [];
        }

        $random = new Randomizer(new Mt19937($seed));
        $base = $random->getInt(1, 8) + ($bookshelves >> 1) + $random->getInt(0, $bookshelves);
        $levels = [max(intdiv($base, 3), 1), intdiv($base * 2, 3) + 1, max($base, $bookshelves * 2)];
        $offers = [];

        foreach($levels as $button => $level){
            $enchantments = self::pick($random, $item, $value, $level);

            if($enchantments !== []){
                $offers[$button] = [$level, $enchantments];
            }
        }

        return $offers;
    }

    /**
     * The words written on a button.
     */
    public static function getButtonText(int $seed, int $button) : string{
        $random = new Randomizer(new Mt19937($seed + $button));
        $name = "";

        for($i = $random->getInt(2, 3); $i > 0; --$i){
            $name .= ($name === "" ? "" : " ") . self::WORDS[$random->getInt(0, count(self::WORDS) - 1)];
        }

        return $name;
    }

    /**
     * @param list<array{Enchantment, int}> $enchantments
     */
    public static function apply(Item $item, array $enchantments) : Item{
        $result = $item->getId() === ItemIds::BOOK ? new Item(ItemIds::ENCHANTED_BOOK) : clone $item;

        foreach($enchantments as [$enchantment, $level]){
            $result->addEnchantment($enchantment, $level);
        }

        return $result;
    }

    /**
     * @return list<array{Enchantment, int}>
     */
    private static function pick(Randomizer $random, Item $item, int $value, int $level) : array{
        $bonus = ($random->getFloat(0, 1) + $random->getFloat(0, 1) - 1) * 0.15;
        $power = $level + 1 + $random->getInt(0, intdiv($value, 4)) + $random->getInt(0, intdiv($value, 4));
        $power = max(1, (int) round($power + $power * $bonus));

        $available = [];

        foreach(Enchantment::cases() as $enchantment){
            if($enchantment->isTreasure() || !$enchantment->canApplyTo($item, false)){
                continue;
            }

            for($enchantLevel = $enchantment->getMaxLevel(); $enchantLevel > 0; --$enchantLevel){
                [$min, $max] = $enchantment->getPowerRange($enchantLevel);

                if($power >= $min && $power <= $max){
                    $available[] = [$enchantment, $enchantLevel];
                    break;
                }
            }
        }

        $chosen = [];

        while($available !== []){
            $picked = self::pickWeighted($random, $available);
            $chosen[] = $picked;

            if($random->getInt(0, 50) > $power){
                break;
            }

            $available = array_values(array_filter($available, fn(array $entry) : bool => $entry[0] !== $picked[0] && $entry[0]->isCompatibleWith($picked[0])));
            $power = intdiv($power, 2);
        }

        return $chosen;
    }

    /**
     * @param list<array{Enchantment, int}> $entries
     *
     * @return array{Enchantment, int}
     */
    private static function pickWeighted(Randomizer $random, array $entries) : array{
        $total = 0;

        foreach($entries as [$enchantment]){
            $total += $enchantment->getWeight();
        }

        $roll = $random->getInt(0, $total - 1);

        foreach($entries as $entry){
            $roll -= $entry[0]->getWeight();

            if($roll < 0){
                return $entry;
            }
        }

        return $entries[0];
    }

    private static function isBookshelf(World $world, int $x, int $y, int $z) : bool{
        return self::getBlockName($world, $x, $y, $z) === BlockIds::BOOKSHELF;
    }

    private static function getBlockName(World $world, int $x, int $y, int $z) : string{
        if($y < Chunk::MIN_Y || $y > Chunk::MAX_Y || !$world->isChunkLoaded($x >> 4, $z >> 4)){
            return BlockIds::AIR;
        }

        return $world->getBlock($x, $y, $z)->getName();
    }
}