<?php

declare(strict_types=1);

namespace raspberry\world\biome;

use raspberry\world\Chunk;

use JsonException;
use RuntimeException;
use UnexpectedValueException;

use function dirname;
use function file_get_contents;
use function is_array;
use function json_decode;
use function str_contains;

use const JSON_THROW_ON_ERROR;

class BiomeRegistry{

    /** @var array<int, Biome>|null */
    private static ?array $byId = null;

    /** @var array<string, Biome> */
    private static array $byName = [];

    private function __construct(){
    }

    /**
     * The biome with this ID, plains for an ID the game does not have.
     *
     * @throws JsonException
     */
    public static function get(int $id) : Biome{
        self::load();
        return self::$byId[$id] ?? self::$byId[Chunk::DEFAULT_BIOME];
    }

    /**
     * By name, "minecraft:" may be left out.
     *
     * @throws JsonException
     */
    public static function getByName(string $name) : ?Biome{
        self::load();
        return self::$byName[str_contains($name, ":") ? $name : "minecraft:" . $name] ?? null;
    }

    /**
     * @return array<int, Biome> keyed by ID
     * @throws JsonException
     */
    public static function getAll() : array{
        self::load();
        return self::$byId;
    }

    /**
     * @throws JsonException
     */
    private static function load() : void{
        if(self::$byId !== null){
            return;
        }

        $path = dirname(__DIR__, 4) . "/resources/vanilla/biome_definitions.json";
        $contents = @file_get_contents($path);

        if($contents === false){
            throw new RuntimeException("Cannot read biome definitions: {$path}");
        }

        $data = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);

        if(!is_array($data) || !is_array($data["biomes"] ?? null)){
            throw new UnexpectedValueException("Biome definitions have an invalid layout: {$path}");
        }

        $byId = [];

        foreach($data["biomes"] as $name => $entry){
            $biome = new Biome(
                $name,
                $entry["id"],
                $entry["temperature"],
                $entry["downfall"],
                $entry["snow_accumulation"][0],
                $entry["snow_accumulation"][1],
                $entry["depth"],
                $entry["scale"],
                $entry["map_water_color"],
                $entry["rain"],
                $entry["tags"]
            );

            $byId[$biome->getId()] = $biome;
            self::$byName[$name] = $biome;
        }

        if(!isset($byId[Chunk::DEFAULT_BIOME])){
            throw new UnexpectedValueException("Biome definitions are missing the default biome: {$path}");
        }

        self::$byId = $byId;
    }
}