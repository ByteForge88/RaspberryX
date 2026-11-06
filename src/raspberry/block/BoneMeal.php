<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\Server;

use raspberry\network\mcpe\protocol\LevelEventPacket;
use raspberry\network\mcpe\protocol\types\LevelEvent;

use raspberry\world\Chunk;
use raspberry\world\World;

use function min;
use function mt_rand;
use function preg_match;

class BoneMeal{

    private const CROPS = '/^minecraft:(wheat|carrots|potatoes|beetroot|torchflower_crop|melon_stem|pumpkin_stem)$/D';

    /** how many spots around a grass block bone meal tries to grow plants on */
    private const GRASS_TRIES = 48;

    private function __construct(){
    }

    public static function grow(World $world, int $x, int $y, int $z) : bool{
        $block = $world->getBlock($x, $y, $z);
        $name = $block->getName();

        $grown = match(true){
            preg_match(self::CROPS, $name) === 1 => self::addStages($world, $x, $y, $z, $block, BlockProperties::GROWTH, mt_rand(2, 5), 7),
            $name === BlockIds::SWEET_BERRY_BUSH => self::addStages($world, $x, $y, $z, $block, BlockProperties::GROWTH, 1, 3),
            $name === BlockIds::COCOA => self::addStages($world, $x, $y, $z, $block, BlockProperties::AGE, 1, 2),
            $name === BlockIds::SHORT_GRASS => self::growTall($world, $x, $y, $z, BlockIds::TALL_GRASS),
            $name === BlockIds::FERN => self::growTall($world, $x, $y, $z, BlockIds::LARGE_FERN),
            $name === BlockIds::GRASS_BLOCK => self::growOnGrass($world, $x, $y, $z),
            default => false
        };

        if($grown){
            $world->broadcastPacket($x, $z, LevelEventPacket::create(LevelEvent::PARTICLE_CROP_GROWTH, $x + 0.5, $y + 0.5, $z + 0.5));
        }

        return $grown;
    }

    private static function addStages(World $world, int $x, int $y, int $z, BlockState $block, string $property, int $stages, int $max) : bool{
        $current = (int) $block->getProperty($property);

        if($current >= $max){
            return false;
        }

        $world->setBlock($x, $y, $z, $block->withProperty($property, min($current + $stages, $max)));

        return true;
    }

    private static function growTall(World $world, int $x, int $y, int $z, string $tall) : bool{
        if($y + 1 > Chunk::MAX_Y || $world->getBlock($x, $y + 1, $z)->getName() !== BlockIds::AIR){
            return false;
        }

        $state = Server::getInstance()->getBlockStateRegistry()->getDefaultState($tall);
        $world->setBlock($x, $y, $z, $state->withProperty(BlockProperties::UPPER_BLOCK_BIT, false));
        $world->setBlock($x, $y + 1, $z, $state->withProperty(BlockProperties::UPPER_BLOCK_BIT, true));

        return true;
    }

    /**
     * Grass and the odd flower come up on the grass around.
     */
    private static function growOnGrass(World $world, int $x, int $y, int $z) : bool{
        if($y + 1 > Chunk::MAX_Y || $world->getBlock($x, $y + 1, $z)->getName() !== BlockIds::AIR){
            return false;
        }

        $registry = Server::getInstance()->getBlockStateRegistry();

        for($try = 0; $try < self::GRASS_TRIES; ++$try){
            [$px, $py, $pz] = [$x + mt_rand(-3, 3), $y + 1 + mt_rand(-1, 1), $z + mt_rand(-3, 3)];

            if($py - 1 < Chunk::MIN_Y || $py > Chunk::MAX_Y || !$world->isChunkLoaded($px >> 4, $pz >> 4)){
                continue;
            }

            if($world->getBlock($px, $py - 1, $pz)->getName() !== BlockIds::GRASS_BLOCK || $world->getBlock($px, $py, $pz)->getName() !== BlockIds::AIR){
                continue;
            }

            $plant = match(mt_rand(0, 7)){
                0 => mt_rand(0, 1) === 0 ? BlockIds::POPPY : BlockIds::DANDELION,
                default => BlockIds::SHORT_GRASS
            };

            $world->setBlock($px, $py, $pz, $registry->getDefaultState($plant));
        }

        return true;
    }
}