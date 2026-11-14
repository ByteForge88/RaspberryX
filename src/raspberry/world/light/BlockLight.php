<?php

declare(strict_types=1);

namespace raspberry\world\light;

use raspberry\block\BlockState;
use raspberry\block\BlockStateIds;
use raspberry\block\BlockStateRegistry;

use raspberry\native\Native;

use RuntimeException;
use UnexpectedValueException;

use function count;
use function dirname;
use function file_get_contents;
use function is_array;
use function json_decode;
use function preg_match;
use function str_contains;

use const JSON_THROW_ON_ERROR;

class BlockLight{

    /** a respawn anchor by its charges */
    private const ANCHOR_LIGHT = [0, 3, 7, 11, 15];

    /** a lit copper bulb by how far it has aged */
    private const BULB_LIGHT = ["copper" => 15, "exposed" => 12, "weathered" => 8, "oxidized" => 4];

    /** blocks with a faint glow the light table leaves out */
    private const FAINT = [
        "minecraft:brewing_stand" => 1,
        "minecraft:end_portal_frame" => 1,
        "minecraft:sculk_sensor" => 1,
        "minecraft:calibrated_sculk_sensor" => 1
    ];

    private static bool $taught = false;

    /** @var array<int, int> */
    private static array $filters = [];

    private function __construct(){
    }

    /**
     * Tells the native side about every block state, once.
     */
    public static function teach(BlockStateRegistry $registry) : void{
        if(self::$taught){
            return;
        }

        $path = dirname(__DIR__, 4) . "/resources/vanilla/block_light.json";
        $contents = @file_get_contents($path);

        if($contents === false){
            throw new RuntimeException("Cannot read block light values: {$path}");
        }

        $data = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);

        if(!is_array($data) || !is_array($data["blocks"] ?? null)){
            throw new UnexpectedValueException("Block light values have an invalid layout: {$path}");
        }

        $blocks = $data["blocks"];

        foreach(self::FAINT as $name => $emission){
            $blocks[$name] = [$emission, $blocks[$name][1] ?? 15];
        }

        //solid blocks whose light the state decides
        $blocks["minecraft:respawn_anchor"] = [0, 15];
        $blocks += self::bulbs();

        $values = [];

        foreach($blocks as $name => [$emission, $filter]){
            if(!$registry->isRegistered($name)){
                continue;
            }

            foreach(self::states($registry, $name) as $state){
                $id = BlockStateIds::get($state);
                $values[$id] = (self::emission($state, $emission) << 4) | $filter;
                self::$filters[$id] = $filter;
            }
        }

        $count = count($values);
        $ffi = Native::get();
        $states = $ffi->new("uint32_t[{$count}]");
        $bytes = $ffi->new("uint8_t[{$count}]");
        $index = 0;

        foreach($values as $id => $value){
            $states[$index] = $id;
            $bytes[$index] = $value;
            ++$index;
        }

        $ffi->rx_light_blocks($states, $bytes, $count);
        self::$taught = true;
    }

    /**
     * How much light the block takes away from light passing through, 15 for a solid block.
     */
    public static function filter(BlockState $state) : int{
        return self::$filters[BlockStateIds::get($state)] ?? ($state->getName() === "minecraft:air" ? 0 : 15);
    }

    /**
     * @return array<string, array{int, int}>
     */
    private static function bulbs() : array{
        $bulbs = [];

        foreach(self::BULB_LIGHT as $age => $light){
            $name = $age === "copper" ? "copper_bulb" : $age . "_copper_bulb";
            $bulbs["minecraft:" . $name] = [$light, 15];
            $bulbs["minecraft:waxed_" . $name] = [$light, 15];
        }

        return $bulbs;
    }

    /**
     * The light a block gives off in this state, $base being what it gives off when it shines.
     */
    private static function emission(BlockState $state, int $base) : int{
        $name = $state->getName();
        $lit = !$state->hasProperty("lit") || $state->getProperty("lit") === true;

        return match(true){
            preg_match('/^minecraft:(\w+_)?candle$/', $name) === 1 => $lit ? 3 * ($state->getProperty("candles") + 1) : 0,
            str_contains($name, "candle_cake") => $lit ? 3 : 0,
            $name === "minecraft:sea_pickle" => $state->getProperty("dead_bit") === true ? 0 : 3 * ($state->getProperty("cluster_count") + 2),
            $name === "minecraft:respawn_anchor" => self::ANCHOR_LIGHT[$state->getProperty("respawn_anchor_charge")] ?? 0,
            str_contains($name, "copper_bulb") => $lit ? $base : 0,
            $state->hasProperty("extinguished") => $state->getProperty("extinguished") === true ? 0 : $base,
            default => $base
        };
    }

    /**
     * Every state a block can be in.
     *
     * @return list<BlockState>
     */
    private static function states(BlockStateRegistry $registry, string $name) : array{
        $combinations = [[]];

        foreach($registry->getProperties($name) as $property => $values){
            $next = [];

            foreach($combinations as $combination){
                foreach($values as $value){
                    $next[] = $combination + [$property => $value];
                }
            }

            $combinations = $next;
        }

        $states = [];

        foreach($combinations as $properties){
            $states[] = $registry->get($name, $properties);
        }

        return $states;
    }
}