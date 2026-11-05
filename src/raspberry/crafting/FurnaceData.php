<?php

declare(strict_types=1);

namespace raspberry\crafting;

use raspberry\item\Item;

use RuntimeException;
use UnexpectedValueException;

use function dirname;
use function file_get_contents;
use function is_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;

class FurnaceData{

    /** experience of smelting an item the table does not list */
    public const DEFAULT_EXPERIENCE = 0.1;

    /** @var array<string, int>|null */
    private static ?array $fuels = null;

    /** @var array<string, float> */
    private static array $experience = [];

    private function __construct(){
    }

    /**
     * How many ticks the item burns in a furnace, 0 when it is no fuel.
     */
    public static function getBurnTicks(Item $item) : int{
        self::load();

        return $item->isNull() ? 0 : self::$fuels[$item->getId()] ?? 0;
    }

    /**
     * The experience smelting one $output gives.
     */
    public static function getExperience(Item $output) : float{
        self::load();

        return self::$experience[$output->getId()] ?? self::DEFAULT_EXPERIENCE;
    }

    private static function load() : void{
        if(self::$fuels !== null){
            return;
        }

        $path = dirname(__DIR__, 3) . "/resources/vanilla/furnace_data.json";
        $contents = @file_get_contents($path);

        if($contents === false){
            throw new RuntimeException("Cannot read furnace data: {$path}");
        }

        $data = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);

        if(!is_array($data) || !is_array($data["fuels"] ?? null) || !is_array($data["experience"] ?? null)){
            throw new UnexpectedValueException("Furnace data has an invalid layout: {$path}");
        }

        self::$fuels = $data["fuels"];
        self::$experience = $data["experience"];
    }
}