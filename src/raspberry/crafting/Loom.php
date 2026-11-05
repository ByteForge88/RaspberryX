<?php

declare(strict_types=1);

namespace raspberry\crafting;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use raspberry\nbt\tag\CompoundTag;
use raspberry\nbt\tag\ListTag;
use raspberry\nbt\tag\Tag;

use function count;
use function in_array;

class Loom{

    public const MAX_PATTERNS = 6;

    /** patterns that need a banner pattern item */
    private const PATTERN_ITEMS = [
        "cre" => ItemIds::CREEPER_BANNER_PATTERN,
        "sku" => ItemIds::SKULL_BANNER_PATTERN,
        "flo" => ItemIds::FLOWER_BANNER_PATTERN,
        "moj" => ItemIds::MOJANG_BANNER_PATTERN,
        "bri" => ItemIds::FIELD_MASONED_BANNER_PATTERN,
        "cbo" => ItemIds::BORDURE_INDENTED_BANNER_PATTERN,
        "glb" => ItemIds::GLOBE_BANNER_PATTERN,
        "pig" => ItemIds::PIGLIN_BANNER_PATTERN,
        "flw" => ItemIds::FLOW_BANNER_PATTERN,
        "gus" => ItemIds::GUSTER_BANNER_PATTERN
    ];

    private const PATTERNS = [
        "bo", "mc", "cr", "lud", "rd", "ld", "rud", "gra", "gru", "hh", "hhb", "vh", "vhr", "mr", "ss",
        "bl", "br", "tl", "tr", "sc", "bs", "cs", "dls", "drs", "ls", "ms", "rs", "ts", "bt", "tt", "bts", "tts"
    ];

    /** banner colors use the old dye numbers */
    private const DYES = [
        ItemIds::BLACK_DYE => 0,
        ItemIds::RED_DYE => 1,
        ItemIds::GREEN_DYE => 2,
        ItemIds::BROWN_DYE => 3,
        ItemIds::BLUE_DYE => 4,
        ItemIds::PURPLE_DYE => 5,
        ItemIds::CYAN_DYE => 6,
        ItemIds::LIGHT_GRAY_DYE => 7,
        ItemIds::GRAY_DYE => 8,
        ItemIds::PINK_DYE => 9,
        ItemIds::LIME_DYE => 10,
        ItemIds::YELLOW_DYE => 11,
        ItemIds::LIGHT_BLUE_DYE => 12,
        ItemIds::MAGENTA_DYE => 13,
        ItemIds::ORANGE_DYE => 14,
        ItemIds::WHITE_DYE => 15
    ];

    private function __construct(){
    }

    /**
     * One banner with the pattern added, null when it cannot be made.
     */
    public static function apply(Item $banner, Item $dye, Item $patternItem, string $pattern) : ?Item{
        $color = self::DYES[$dye->getId()] ?? null;

        if($banner->getId() !== ItemIds::BANNER || $color === null || ($banner->getExtraTags()->getInt("Type", 0) ?? 0) !== 0){
            return null;
        }

        $needed = self::PATTERN_ITEMS[$pattern] ?? null;

        if($needed !== null ? $patternItem->getId() !== $needed : !in_array($pattern, self::PATTERNS, true)){
            return null;
        }

        $result = (clone $banner)->setCount(1);
        $patterns = $result->getExtraTags()->getListTag("Patterns")?->getValue() ?? [];

        if(count($patterns) >= self::MAX_PATTERNS){
            return null;
        }

        $list = new ListTag([], Tag::TAG_COMPOUND);

        foreach($patterns as $entry){
            $list->push($entry);
        }

        $list->push((new CompoundTag())->setString("Pattern", $pattern)->setInt("Color", $color));
        $result->getExtraTags()->setTag("Patterns", $list);

        return $result;
    }
}