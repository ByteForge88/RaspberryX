<?php

declare(strict_types=1);

namespace raspberry\block\growth;

use raspberry\Server;

use raspberry\block\BlockIds;
use raspberry\block\BlockProperties;
use raspberry\block\BlockState;

use raspberry\world\World;

use raspberry\event\block\BlockFadeEvent;

use function in_array;

class Farmland implements RandomTickBehavior{

    private const WET = 7;

    private const WATER_REACH = 4;

    public function getBlockNames() : array{
        return [BlockIds::FARMLAND];
    }

    public function ticks(BlockState $state) : bool{
        return true;
    }

    public function onRandomTick(World $world, int $x, int $y, int $z, BlockState $state) : void{
        $moisture = $state->getProperty(BlockProperties::MOISTURIZED_AMOUNT);

        if(self::isNearWater($world, $x, $y, $z) || $world->isRainingAt($x, $y + 1, $z)){
            if($moisture < self::WET){
                $world->setBlock($x, $y, $z, $state->withProperty(BlockProperties::MOISTURIZED_AMOUNT, self::WET));
            }
        }elseif($moisture > 0){
            $world->setBlock($x, $y, $z, $state->withProperty(BlockProperties::MOISTURIZED_AMOUNT, $moisture - 1));
        }elseif(!in_array(Nearby::name($world, $x, $y + 1, $z), Crops::NAMES, true)){
            BlockFadeEvent::set($world, $x, $y, $z, Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::DIRT));
        }
    }

    private static function isNearWater(World $world, int $x, int $y, int $z) : bool{
        for($dx = -self::WATER_REACH; $dx <= self::WATER_REACH; ++$dx){
            for($dz = -self::WATER_REACH; $dz <= self::WATER_REACH; ++$dz){
                for($dy = 0; $dy <= 1; ++$dy){
                    if(Nearby::isWater(Nearby::block($world, $x + $dx, $y + $dy, $z + $dz))){
                        return true;
                    }
                }
            }
        }

        return false;
    }
}