<?php

declare(strict_types=1);

namespace raspberry\block\growth;

use raspberry\Server;

use raspberry\block\BlockIds;
use raspberry\block\BlockProperties;
use raspberry\block\BlockState;

use raspberry\world\World;
use raspberry\world\light\BlockLight;

use function mt_rand;

class Spreading implements RandomTickBehavior{

    private const NYLIUM = ["minecraft:crimson_nylium", "minecraft:warped_nylium"];

    private const TRIES = 4;

    public function getBlockNames() : array{
        return [BlockIds::GRASS_BLOCK, BlockIds::MYCELIUM, ...self::NYLIUM];
    }

    public function ticks(BlockState $state) : bool{
        return true;
    }

    public function onRandomTick(World $world, int $x, int $y, int $z, BlockState $state) : void{
        $registry = Server::getInstance()->getBlockStateRegistry();
        $name = $state->getName();

        if($name !== BlockIds::GRASS_BLOCK && $name !== BlockIds::MYCELIUM){
            if(!self::canStayCovered($world, $x, $y, $z)){
                $world->setBlock($x, $y, $z, $registry->getDefaultState(BlockIds::NETHERRACK));
            }

            return;
        }

        if(!self::canStayCovered($world, $x, $y, $z)){
            $world->setBlock($x, $y, $z, $registry->getDefaultState(BlockIds::DIRT));
            return;
        }

        if($world->getLight($x, $y + 1, $z) < 9){
            return;
        }

        for($try = 0; $try < self::TRIES; ++$try){
            [$px, $py, $pz] = [$x + mt_rand(-1, 1), $y + mt_rand(-3, 1), $z + mt_rand(-1, 1)];

            if(Nearby::name($world, $px, $py, $pz) === BlockIds::DIRT && self::canStayCovered($world, $px, $py, $pz) && !Nearby::isWater(Nearby::block($world, $px, $py + 1, $pz))){
                $world->setBlock($px, $py, $pz, $state);
            }
        }
    }

    private static function canStayCovered(World $world, int $x, int $y, int $z) : bool{
        $above = Nearby::block($world, $x, $y + 1, $z);

        if($above === null){
            return true;
        }

        if($above->getName() === BlockIds::SNOW_LAYER && $above->getProperty(BlockProperties::HEIGHT) === 0){
            return true;
        }

        if(Nearby::isWater($above) && $above->getProperty("liquid_depth") === 0){
            return false;
        }

        return BlockLight::filter($above) < 15;
    }
}