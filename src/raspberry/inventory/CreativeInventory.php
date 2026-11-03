<?php

declare(strict_types=1);

namespace raspberry\inventory;

use raspberry\item\Item;

use RuntimeException;
use UnexpectedValueException;

use function count;
use function dirname;
use function file_get_contents;
use function is_array;
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

    /** @var list<array{int, Item}> category, item */
    private array $entries = [];

    public function __construct(?string $path = null){
        $path ??= dirname(__DIR__, 3) . "/resources/vanilla/creative_items.json";
        $contents = @file_get_contents($path);

        if($contents === false){
            throw new RuntimeException("Cannot read creative items: {$path}");
        }

        $data = json_decode($contents, true, 4, JSON_THROW_ON_ERROR);

        if(!is_array($data) || !is_array($data["items"] ?? null)){
            throw new UnexpectedValueException("Creative items have an invalid layout: {$path}");
        }

        foreach($data["items"] as $entry){
            if(!is_array($entry) || count($entry) !== 3 || !is_string($entry[0]) || !isset(self::CATEGORIES[$entry[0]]) || !is_string($entry[1]) || !is_int($entry[2])){
                throw new UnexpectedValueException("Invalid creative item entry in {$path}");
            }

            $this->entries[] = [self::CATEGORIES[$entry[0]], new Item($entry[1], 1, $entry[2])];
        }
    }

    /**
     * @return list<array{int, Item}>
     */
    public function getEntries() : array{
        return $this->entries;
    }
}