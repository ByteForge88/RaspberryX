<?php

declare(strict_types=1);

namespace raspberry\world\generator;

use raspberry\block\BlockState;
use raspberry\block\BlockStateIds;
use raspberry\block\BlockStateRegistry;

use raspberry\native\Native;

use FFI;
use FFI\CData;
use UnexpectedValueException;

use function count;
use function explode;
use function is_bool;
use function preg_match;
use function var_export;

/**
 * The blocks the native generators place, as state IDs. The library lists them as state
 * strings like minecraft:oak_log[pillar_axis=y]; each is checked against the registry once.
 */
class NativeBlocks{

    private static ?CData $ids = null;

    private static int $count = 0;

    private function __construct(){
    }

    /**
     * @return array{CData, int} the IDs as a uint32_t array, and how many there are
     */
    public static function get(BlockStateRegistry $registry) : array{
        if(self::$ids !== null){
            return [self::$ids, self::$count];
        }

        $ffi = Native::get();
        $out = $ffi->new("char*");
        $length = $ffi->new("size_t");
        $ffi->rx_generator_states(FFI::addr($out), FFI::addr($length));
        $states = explode("\n", Native::take($out, $length->cdata));

        $ids = $ffi->new("uint32_t[" . count($states) . "]");

        foreach($states as $index => $state){
            $ids[$index] = BlockStateIds::get(self::parse($registry, $state));
        }

        self::$count = count($states);

        return [self::$ids = $ids, self::$count];
    }

    /**
     * Turns minecraft:name[property=value,...] into a block state.
     */
    private static function parse(BlockStateRegistry $registry, string $state) : BlockState{
        if(preg_match('/^([a-z0-9_:]+)(?:\[(.*)\])?$/D', $state, $match) !== 1 || !$registry->isRegistered($match[1])){
            throw new UnexpectedValueException("The native library uses an unknown block {$state}");
        }

        $properties = [];
        $known = $registry->getProperties($match[1]);

        foreach(($match[2] ?? "") === "" ? [] : explode(",", $match[2]) as $pair){
            [$name, $text] = explode("=", $pair, 2) + [1 => ""];

            foreach($known[$name] ?? [] as $value){
                if((is_bool($value) ? var_export($value, true) : (string) $value) === $text){
                    $properties[$name] = $value;
                    continue 2;
                }
            }

            throw new UnexpectedValueException("The native library uses an unknown property {$pair} on {$match[1]}");
        }

        return $registry->get($match[1], $properties);
    }
}
