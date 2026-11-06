<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\network\mcpe\convert\BlockTranslator;

use UnexpectedValueException;

class BlockStateIds{

    /** @var array<string, int> */
    private static array $ids = [];

    /** @var array<int, BlockState> */
    private static array $states = [];

    private function __construct(){
    }

    /**
     * The state's ID, unsigned 32-bit.
     */
    public static function get(BlockState $state) : int{
        $key = $state->getKey();

        if(isset(self::$ids[$key])){
            return self::$ids[$key];
        }

        $id = BlockTranslator::hash($state);
        self::$states[$id] ??= $state;

        return self::$ids[$key] = $id;
    }

    public static function fromId(int $id) : BlockState{
        return self::$states[$id] ?? throw new UnexpectedValueException("Unknown block state ID {$id}");
    }
}