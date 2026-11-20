<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\Server;

use raspberry\item\Item;
use raspberry\item\ItemIds;
use raspberry\item\enchantment\Enchantment;

use JsonException;

use function count;
use function max;
use function min;
use function mt_getrandmax;
use function mt_rand;
use function preg_match;
use function str_replace;

class BlockLoot{

    private const string SILK_TOUCH = '/^minecraft:(\w*_ore|\w*glass(_pane)?|ice|packed_ice|blue_ice|grass_block|mycelium|podzol|crimson_nylium|warped_nylium|' .
    '\w+_leaves(_flowered)?|bookshelf|chiseled_bookshelf|glowstone|sea_lantern|melon_block|clay|snow|stone|deepslate|web|campfire|soul_campfire|ender_chest|' .
    'brown_mushroom_block|red_mushroom_block|turtle_egg|sculk(_sensor|_catalyst|_shrieker|_vein)?|calibrated_sculk_sensor|amethyst_cluster|' .
    '(small|medium|large)_amethyst_bud|beehive|bee_nest)$/D';

    private const string SHEARS = '/^minecraft:(\w+_leaves(_flowered)?|short_grass|tall_grass|fern|large_fern|deadbush|vine|glow_lichen|seagrass|web|hanging_roots)$/D';

    private const string ORES = '/^minecraft:(\w*_ore|amethyst_cluster)$/D';

    private const array UNIFORM_BONUS = [
        ItemIds::REDSTONE => [1, null],
        ItemIds::GLOWSTONE_DUST => [1, 4],
        ItemIds::PRISMARINE_CRYSTALS => [1, 5],
        ItemIds::MELON_SLICE => [1, 9],
        ItemIds::NETHER_WART => [1, null],
        ItemIds::WHEAT_SEEDS => [2, null]
    ];

    private const array CROPS = [
        "minecraft:wheat" => ItemIds::WHEAT_SEEDS,
        "minecraft:carrots" => ItemIds::CARROT,
        "minecraft:potatoes" => ItemIds::POTATO,
        "minecraft:beetroot" => ItemIds::BEETROOT_SEEDS
    ];

    private const float CROP_BONUS_CHANCE = 0.5714286;

    private const array FLINT_CHANCES = [0.1, 0.14285715, 0.25, 1.0];

    private const array LEAF_CHANCES = [
        "0.05" => [0.05, 0.0625, 0.083333336, 0.1],
        "0.025" => [0.025, 0.027777778, 0.03125, 0.041666668, 0.1],
        "0.02" => [0.02, 0.022222223, 0.025, 0.033333335, 0.1],
        "0.005" => [0.005, 0.0055555557, 0.00625, 0.008333334, 0.025]
    ];

    private function __construct(){
    }

    /**
     * @return list<Item>
     * @throws JsonException
     */
    public static function getDrops(BlockState $block, Item $tool) : array{
        $name = $block->getName();
        $info = BlockBreakInfo::get($name);

        if($tool->getEnchantmentLevel(Enchantment::SILK_TOUCH) > 0 && preg_match(self::SILK_TOUCH, $name) === 1){
            $self = self::itemOf($name);

            if($self !== null){
                return [$self];
            }
        }

        if($tool->getId() === ItemIds::SHEARS && preg_match(self::SHEARS, $name) === 1){
            $self = self::itemOf($name);

            if($self !== null){
                return [$self];
            }
        }

        $fortune = $tool->getEnchantmentLevel(Enchantment::FORTUNE);

        if($name === BlockIds::GRAVEL){
            $chance = self::FLINT_CHANCES[min($fortune, 3)];

            return [new Item(mt_rand() / mt_getrandmax() < $chance ? ItemIds::FLINT : BlockIds::GRAVEL)];
        }

        if($name === BlockIds::MELON_BLOCK){
            return $info->canHarvest($tool) ? [self::uniformBonus(new Item(ItemIds::MELON_SLICE, mt_rand(3, 7)), $fortune)] : [];
        }

        if($fortune > 0 && preg_match('/_leaves(_flowered)?$/D', $name) === 1){
            return self::rollLeaves($info, $fortune);
        }

        $drops = $info->getDrops($block, $tool);

        if($fortune <= 0){
            return $drops;
        }

        $ore = preg_match(self::ORES, $name) === 1;

        foreach($drops as $index => $drop){
            if($ore && !isset(self::UNIFORM_BONUS[$drop->getId()])){
                $drops[$index] = $drop->setCount($drop->getCount() * (max(0, mt_rand(0, $fortune + 1) - 1) + 1));
            }elseif(isset(self::UNIFORM_BONUS[$drop->getId()]) && !isset(self::CROPS[$name])){
                $drops[$index] = self::uniformBonus($drop, $fortune);
            }
        }

        $bonus = self::CROPS[$name] ?? null;

        if($bonus !== null && $block->hasProperty(BlockProperties::GROWTH) && (int) $block->getProperty(BlockProperties::GROWTH) >= 7){
            $extra = 0;

            for($i = 0; $i < $fortune; ++$i){
                $extra += mt_rand() / mt_getrandmax() < self::CROP_BONUS_CHANCE ? 1 : 0;
            }

            if($extra > 0){
                $drops[] = new Item($bonus, $extra);
            }
        }

        return $drops;
    }

    private static function uniformBonus(Item $drop, int $fortune) : Item{
        [$multiplier, $limit] = self::UNIFORM_BONUS[$drop->getId()];
        $count = $drop->getCount() + mt_rand(0, $fortune * $multiplier);

        return $drop->setCount($limit === null ? $count : min($limit, $count));
    }

    /**
     * @return list<Item>
     */
    private static function rollLeaves(BlockBreakInfo $info, int $fortune) : array{
        $drops = [];

        foreach($info->getDropEntries() as $entry){
            $chance = $entry["chance"] ?? 1.0;
            $table = self::LEAF_CHANCES[(string) $chance] ?? null;

            if($table !== null){
                $chance = $table[min($fortune, count($table) - 1)];
            }

            if(mt_rand() / mt_getrandmax() < $chance){
                $drops[] = new Item($entry["item"], mt_rand($entry["min"], $entry["max"]));
            }
        }

        return $drops;
    }

    private static function itemOf(string $blockName) : ?Item{
        $name = str_replace("minecraft:lit_", "minecraft:", $blockName);

        return Server::getInstance()->getItemTypeDictionary()->getRuntimeId($name) !== null ? new Item($name) : null;
    }
}