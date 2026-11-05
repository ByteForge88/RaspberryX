<?php

declare(strict_types=1);

namespace raspberry\inventory;

use raspberry\item\Item;
use raspberry\item\enchantment\Enchantment;

use raspberry\nbt\tag\ByteArrayTag;
use raspberry\nbt\tag\CompoundTag;
use raspberry\nbt\tag\ListTag;
use raspberry\nbt\tag\Tag;

use RuntimeException;
use UnexpectedValueException;

use function chr;
use function dirname;
use function file_get_contents;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

class CreativeInventory{

    public const CATEGORY_CONSTRUCTION = 1;
    public const CATEGORY_NATURE = 2;
    public const CATEGORY_EQUIPMENT = 3;
    public const CATEGORY_ITEMS = 4;

    private const CATEGORIES = [
        "construction" => self::CATEGORY_CONSTRUCTION,
        "nature" => self::CATEGORY_NATURE,
        "equipment" => self::CATEGORY_EQUIPMENT,
        "items" => self::CATEGORY_ITEMS
    ];

    /** @var list<array{int, string, ?Item, list<Item>}> */
    private array $groups = [];

    public function __construct(?string $path = null){
        $path ??= dirname(__DIR__, 3) . "/resources/vanilla/creative_items.json";
        $contents = @file_get_contents($path);

        if($contents === false){
            throw new RuntimeException("Cannot read creative items: {$path}");
        }

        $data = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);

        if(!is_array($data) || !is_array($data["groups"] ?? null)){
            throw new UnexpectedValueException("Creative items have an invalid layout: {$path}");
        }

        foreach($data["groups"] as $group){
            if(!is_array($group) || !is_string($group["category"] ?? null) || !isset(self::CATEGORIES[$group["category"]]) || !is_array($group["items"] ?? null)){
                throw new UnexpectedValueException("Invalid creative group in {$path}");
            }

            $name = $group["name"] ?? "";

            if(!is_string($name)){
                throw new UnexpectedValueException("Invalid creative group name in {$path}");
            }

            $items = [];

            foreach($group["items"] as $entry){
                $items[] = self::readItem($entry, $path);
            }

            $icon = isset($group["icon"]) ? self::readItem($group["icon"], $path) : null;
            $this->groups[] = [self::CATEGORIES[$group["category"]], $name, $icon, $items];
        }
    }

    /**
     * @return list<array{int, string, ?Item, list<Item>}>
     */
    public function getGroups() : array{
        return $this->groups;
    }

    private static function readItem(mixed $entry, string $path) : Item{
        if(is_string($entry)){
            return new Item($entry);
        }

        if(!is_array($entry) || !is_string($entry[0] ?? null) || !is_int($entry[1] ?? null) || (isset($entry[2]) && !is_array($entry[2]))){
            throw new UnexpectedValueException("Invalid creative item in {$path}");
        }

        $item = new Item($entry[0], 1, $entry[1]);
        $extra = $entry[2] ?? [];

        if(isset($extra["enchantment"])){
            $enchantment = is_string($extra["enchantment"]) ? Enchantment::fromName($extra["enchantment"]) : null;

            if($enchantment === null || !is_int($extra["level"] ?? null)){
                throw new UnexpectedValueException("Invalid enchanted book in {$path}");
            }

            $item->addEnchantment($enchantment, $extra["level"]);
        }

        if(isset($extra["flight"])){
            if(!is_int($extra["flight"]) || (isset($extra["color"]) && !is_int($extra["color"]))){
                throw new UnexpectedValueException("Invalid firework rocket in {$path}");
            }

            $explosions = new ListTag([], Tag::TAG_COMPOUND);

            if(isset($extra["color"])){
                $explosions->push(self::explosion($extra["color"]));
            }

            $item->getExtraTags()->setTag("Fireworks", (new CompoundTag())
                ->setTag("Explosions", $explosions)
                ->setByte("Flight", $extra["flight"]));
        }elseif(isset($extra["custom_color"])){
            if(!is_int($extra["custom_color"]) || !is_int($extra["color"] ?? null)){
                throw new UnexpectedValueException("Invalid firework star in {$path}");
            }

            $item->getExtraTags()->setTag("FireworksItem", self::explosion($extra["color"]));
            $item->getExtraTags()->setInt("customColor", $extra["custom_color"]);
        }

        if(isset($extra["ominous"])){
            if(!is_bool($extra["ominous"])){
                throw new UnexpectedValueException("Invalid banner in {$path}");
            }

            $item->getExtraTags()->setInt("Type", $extra["ominous"] ? 1 : 0);
        }

        return $item;
    }

    /**
     * A plain small ball firework in one dye color.
     */
    private static function explosion(int $color) : CompoundTag{
        return (new CompoundTag())
            ->setTag("FireworkColor", new ByteArrayTag(chr($color)))
            ->setTag("FireworkFade", new ByteArrayTag(""))
            ->setByte("FireworkFlicker", 0)
            ->setByte("FireworkTrail", 0)
            ->setByte("FireworkType", 0);
    }
}