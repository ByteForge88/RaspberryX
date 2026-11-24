<?php

declare(strict_types=1);

namespace raspberry\world\spawn;

use JsonException;
use RuntimeException;
use UnexpectedValueException;

use function dirname;
use function file_get_contents;
use function is_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;

class SpawnRules{

    public const string MONSTER = "monster";
    public const string ANIMAL = "animal";

    private const array DEFAULT_SIZE = [0.6, 1.8];

    /** @var array<string, list<SpawnCondition>>|null */
    private static ?array $populations = null;

    /** @var array<string, array{float, float}> */
    private static array $sizes = [];

    /** @var array<string, true> */
    private static array $limited = [];

    private function __construct(){
    }

    /**
     * @return array<string, list<SpawnCondition>>
     * @throws JsonException
     */
    private static function load() : array{
        if(self::$populations !== null){
            return self::$populations;
        }

        $path = dirname(__DIR__, 4) . "/resources/vanilla/spawn_rules.json";
        $contents = @file_get_contents($path);

        if($contents === false){
            throw new RuntimeException("Cannot read spawn rules: $path");
        }

        $data = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);

        if(!is_array($data) || !is_array($data["rules"] ?? null) || !is_array($data["sizes"] ?? null)){
            throw new UnexpectedValueException("Spawn rules have an invalid layout: $path");
        }

        $populations = [];

        foreach($data["rules"] as $type => $rule){
            foreach($rule["conditions"] as $entry){
                $condition = new SpawnCondition($type, $rule["population"], $entry);
                $populations[$rule["population"]][] = $condition;

                if($condition->hasDensityLimit()){
                    self::$limited[$type] = true;
                }
            }
        }

        foreach($data["sizes"] as $type => [$width, $height]){
            self::$sizes[$type] = [(float) $width, (float) $height];
        }

        return self::$populations = $populations;
    }

    /**
     * @return list<SpawnCondition>
     * @throws JsonException
     */
    public static function getPopulation(string $population) : array{
        return self::load()[$population] ?? [];
    }

    /**
     * @throws JsonException
     */
    public static function hasDensityLimit(string $type) : bool{
        self::load();
        return isset(self::$limited[$type]);
    }

    /**
     * @return array{float, float}
     * @throws JsonException
     */
    public static function getSize(string $type) : array{
        self::load();
        return self::$sizes[$type] ?? self::DEFAULT_SIZE;
    }
}