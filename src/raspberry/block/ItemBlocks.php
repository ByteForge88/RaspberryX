<?php

declare(strict_types=1);

namespace raspberry\block;

use RuntimeException;
use UnexpectedValueException;

use function dirname;
use function file_get_contents;
use function in_array;
use function is_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;

class ItemBlocks{

    /** @var array<string, array{block: string, wall?: string}>|null */
    private static ?array $items = null;

    /** @var list<string> */
    private static array $notPlaceable = [];

    private function __construct(){
    }

    /**
     * @return array{string, ?string}|null
     */
    public static function get(string $itemId, BlockStateRegistry $registry) : ?array{
        self::$items ??= self::load(dirname(__DIR__, 3) . "/resources/vanilla/item_blocks.json");

        if(isset(self::$items[$itemId])){
            return [self::$items[$itemId]["block"], self::$items[$itemId]["wall"] ?? null];
        }

        if(in_array($itemId, self::$notPlaceable, true) || !$registry->isRegistered($itemId)){
            return null;
        }

        return [$itemId, null];
    }

    /**
     * @return array<string, array{block: string, wall?: string}>
     */
    private static function load(string $path) : array{
        $contents = @file_get_contents($path);

        if($contents === false){
            throw new RuntimeException("Cannot read item blocks: {$path}");
        }

        $data = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);

        if(!is_array($data) || !is_array($data["items"] ?? null) || !is_array($data["not_placeable"] ?? null)){
            throw new UnexpectedValueException("Item blocks have an invalid layout: {$path}");
        }

        self::$notPlaceable = $data["not_placeable"];

        return $data["items"];
    }
}