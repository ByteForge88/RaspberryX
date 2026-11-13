<?php

declare(strict_types=1);

namespace raspberry\entity;

use RuntimeException;
use UnexpectedValueException;

use function array_search;
use function dirname;
use function file_get_contents;
use function is_array;
use function json_decode;

use const JSON_THROW_ON_ERROR;

class EntityProperties{

    /** @var array<string, list<array{name: string, type: string, values?: list<string>, min?: int|float, max?: int|float}>>|null */
    private static ?array $entities = null;

    private function __construct(){
    }

    /**
     * @return array<string, list<array{name: string, type: string, values?: list<string>, min?: int|float, max?: int|float}>>
     */
    public static function getAll() : array{
        if(self::$entities === null){
            $path = dirname(__DIR__, 3) . "/resources/vanilla/entity_properties.json";
            $contents = @file_get_contents($path);

            if($contents === false){
                throw new RuntimeException("Cannot read entity properties: {$path}");
            }

            $data = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);

            if(!is_array($data) || !is_array($data["entities"] ?? null)){
                throw new UnexpectedValueException("Entity properties have an invalid layout: {$path}");
            }

            self::$entities = $data["entities"];
        }

        return self::$entities;
    }

    /**
     * @param array<string, string|int|float|bool> $values
     *
     * @return array{array<int, int>, array<int, float>}
     */
    public static function encode(string $entity, array $values) : array{
        $ints = [];
        $floats = [];

        foreach(self::getAll()[$entity] ?? [] as $index => $property){
            $value = $values[$property["name"]] ?? null;

            if($value === null){
                continue;
            }

            if($property["type"] === "float"){
                $floats[$index] = (float) $value;
            }elseif($property["type"] === "enum"){
                $place = array_search($value, $property["values"], true);

                if($place !== false){
                    $ints[$index] = $place;
                }
            }else{
                $ints[$index] = (int) $value;
            }
        }

        return [$ints, $floats];
    }
}