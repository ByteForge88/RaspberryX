<?php

declare(strict_types=1);

namespace raspberry\item;

use RuntimeException;
use UnexpectedValueException;

use function dirname;
use function explode;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function strpos;
use function substr;
use function ucfirst;

use const JSON_THROW_ON_ERROR;

class ItemNames{

    /** @var array<string, string>|null item ID => name */
    private static ?array $names = null;

    private function __construct(){
    }

    /**
     * The item's English name. Unknown IDs get a name made from the ID: "minecraft:magic_wand" => "Magic Wand".
     */
    public static function get(string $id) : string{
        self::$names ??= self::load(dirname(__DIR__, 3) . "/resources/vanilla/item_names.json");

        return self::$names[$id] ?? self::fromId($id);
    }

    public static function fromId(string $id) : string{
        $colon = strpos($id, ":");
        $words = explode("_", $colon === false ? $id : substr($id, $colon + 1));

        foreach($words as $index => $word){
            $small = $index > 0 && in_array($word, ["of", "the", "and", "with", "on", "a", "in", "to"], true);
            $words[$index] = $small ? $word : ucfirst($word);
        }

        return implode(" ", $words);
    }

    /**
     * @return array<string, string>
     */
    private static function load(string $path) : array{
        $contents = @file_get_contents($path);

        if($contents === false){
            throw new RuntimeException("Cannot read item names: {$path}");
        }

        $data = json_decode($contents, true, 4, JSON_THROW_ON_ERROR);

        if(!is_array($data) || !is_array($data["names"] ?? null)){
            throw new UnexpectedValueException("Item names have an invalid layout: {$path}");
        }

        $names = [];

        foreach($data["names"] as $id => $name){
            if(!is_string($id) || !is_string($name)){
                throw new UnexpectedValueException("Invalid item name entry in {$path}");
            }

            $names[$id] = $name;
        }

        return $names;
    }
}