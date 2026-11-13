<?php

declare(strict_types=1);

namespace raspberry\entity\ai;

use RuntimeException;

use function count;
use function implode;
use function in_array;
use function sort;

class TemptItems{

    public const MAX_GROUPS = 64;

    /** @var array<string, int> */
    private static array $groups = [];

    /** @var list<list<string>> */
    private static array $items = [];

    /** @var array<string, int> */
    private static array $masks = [];

    private function __construct(){
    }

    /**
     * @param list<string> $items
     */
    public static function group(array $items) : int{
        sort($items);
        $key = implode(",", $items);

        if(!isset(self::$groups[$key])){
            if(count(self::$items) >= self::MAX_GROUPS){
                throw new RuntimeException("There can only be " . self::MAX_GROUPS . " different sets of tempt items");
            }

            self::$groups[$key] = count(self::$items);
            self::$items[] = $items;
            self::$masks = [];
        }

        return self::$groups[$key];
    }

    public static function mask(string $itemId) : int{
        if(!isset(self::$masks[$itemId])){
            $mask = 0;

            foreach(self::$items as $group => $items){
                if(in_array($itemId, $items, true)){
                    $mask |= 1 << $group;
                }
            }

            self::$masks[$itemId] = $mask;
        }

        return self::$masks[$itemId];
    }
}