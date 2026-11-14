<?php

declare(strict_types=1);

namespace raspberry\block\growth;

use raspberry\block\BlockIds;
use raspberry\block\BlockState;

use raspberry\world\Chunk;
use raspberry\world\World;

use function max;

class Nearby{

    /** x, y, z of the four sides */
    public const HORIZONTAL = [[0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]];

    private function __construct(){
    }

    /**
     * The block, null outside the world or in a chunk that is not loaded.
     */
    public static function block(World $world, int $x, int $y, int $z) : ?BlockState{
        if($y < Chunk::MIN_Y || $y > Chunk::MAX_Y || !$world->isChunkLoaded($x >> 4, $z >> 4)){
            return null;
        }

        return $world->getBlock($x, $y, $z);
    }

    /**
     * The block's name, "" where block() is null.
     */
    public static function name(World $world, int $x, int $y, int $z) : string{
        return self::block($world, $x, $y, $z)?->getName() ?? "";
    }

    public static function isAir(World $world, int $x, int $y, int $z) : bool{
        return self::name($world, $x, $y, $z) === BlockIds::AIR;
    }

    /**
     * The brighter of the block light and the sky light, whatever the time of day.
     */
    public static function rawLight(World $world, int $x, int $y, int $z) : int{
        return max($world->getBlockLight($x, $y, $z), $world->getSkyLight($x, $y, $z));
    }

    public static function isWaterSource(?BlockState $block) : bool{
        return $block !== null && $block->getName() === BlockIds::WATER && $block->getProperty("liquid_depth") === 0;
    }

    public static function isWater(?BlockState $block) : bool{
        $name = $block?->getName();
        return $name === BlockIds::WATER || $name === BlockIds::FLOWING_WATER;
    }
}