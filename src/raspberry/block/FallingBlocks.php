<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\Server;

use raspberry\entity\object\FallingBlock;

use raspberry\event\block\BlockFallEvent;

use raspberry\math\Vector3;

use raspberry\world\Chunk;
use raspberry\world\World;

use function preg_match;

class FallingBlocks{

    public const DELAY = 2;

    private const FALLING = '/^minecraft:(sand|red_sand|gravel|\w+_concrete_powder|anvil|chipped_anvil|damaged_anvil|dragon_egg|suspicious_sand|suspicious_gravel)$/D';

    /** what a falling block falls through and lands in place of */
    private const FALLS_THROUGH = '/^minecraft:(air|water|flowing_water|lava|flowing_lava|fire|soul_fire|short_grass|tall_grass|fern|large_fern|deadbush|dead_bush|vine|seagrass|' .
    'structure_void|light_block_\d+|bubble_column|glow_lichen|hanging_roots|crimson_roots|warped_roots|nether_sprouts|leaf_litter|short_dry_grass|tall_dry_grass)$/D';

    private function __construct(){
    }

    public static function falls(BlockState $block) : bool{
        return preg_match(self::FALLING, $block->getName()) === 1;
    }

    public static function canFallThrough(BlockState $block) : bool{
        if($block->getName() === BlockIds::SNOW_LAYER){
            return $block->getProperty(BlockProperties::HEIGHT) === 0;
        }

        return preg_match(self::FALLS_THROUGH, $block->getName()) === 1;
    }

    public static function tick(World $world, int $x, int $y, int $z, BlockState $block) : void{
        if($y <= Chunk::MIN_Y || !$world->isChunkLoaded($x >> 4, $z >> 4) || !self::canFallThrough($world->getBlock($x, $y - 1, $z))){
            return;
        }

        $event = new BlockFallEvent($world, new Vector3($x, $y, $z), $block);
        $event->call();

        if($event->isCancelled()){
            return;
        }

        $world->setBlock($x, $y, $z, Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::AIR));
        new FallingBlock($world, new Vector3($x + 0.5, $y, $z + 0.5), $block);
    }
}