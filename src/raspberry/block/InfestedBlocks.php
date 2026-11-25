<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\Server;

use raspberry\entity\monster\Silverfish;

use raspberry\math\Vector3;

use raspberry\world\World;

use function array_flip;

class InfestedBlocks{

    private const array HOSTS = [
        BlockIds::INFESTED_STONE => BlockIds::STONE,
        BlockIds::INFESTED_COBBLESTONE => BlockIds::COBBLESTONE,
        BlockIds::INFESTED_STONE_BRICKS => BlockIds::STONE_BRICKS,
        BlockIds::INFESTED_MOSSY_STONE_BRICKS => BlockIds::MOSSY_STONE_BRICKS,
        BlockIds::INFESTED_CRACKED_STONE_BRICKS => BlockIds::CRACKED_STONE_BRICKS,
        BlockIds::INFESTED_CHISELED_STONE_BRICKS => BlockIds::CHISELED_STONE_BRICKS,
        BlockIds::INFESTED_DEEPSLATE => BlockIds::DEEPSLATE
    ];

    private function __construct(){
    }

    public static function isInfested(BlockState $block) : bool{
        return isset(self::HOSTS[$block->getName()]);
    }

    public static function canInfest(BlockState $block) : bool{
        return isset(array_flip(self::HOSTS)[$block->getName()]);
    }

    public static function toHost(BlockState $block) : BlockState{
        return self::swap($block, self::HOSTS[$block->getName()] ?? $block->getName());
    }

    public static function toInfested(BlockState $block) : BlockState{
        return self::swap($block, array_flip(self::HOSTS)[$block->getName()] ?? $block->getName());
    }

    private static function swap(BlockState $block, string $name) : BlockState{
        return Server::getInstance()->getBlockStateRegistry()->get($name, $block->getProperties());
    }

    public static function release(World $world, int $x, int $y, int $z) : Silverfish{
        return new Silverfish($world, new Vector3($x + 0.5, $y, $z + 0.5));
    }
}