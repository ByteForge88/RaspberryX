<?php

declare(strict_types=1);

namespace raspberry\block\growth;

use raspberry\Server;

use raspberry\block\BlockIds;
use raspberry\block\BlockProperties;
use raspberry\block\BlockState;

use raspberry\world\World;
use raspberry\world\light\BlockLight;

use raspberry\event\block\BlockFadeEvent;

use function mt_rand;

class Melting implements RandomTickBehavior{

    private const MELT_LIGHT = 11;

    private const FROSTED_ICE_MAX_AGE = 3;

    public function getBlockNames() : array{
        return [BlockIds::ICE, BlockIds::SNOW_LAYER, BlockIds::FROSTED_ICE];
    }

    public function ticks(BlockState $state) : bool{
        return true;
    }

    public function onRandomTick(World $world, int $x, int $y, int $z, BlockState $state) : void{
        $registry = Server::getInstance()->getBlockStateRegistry();

        match($state->getName()){
            BlockIds::ICE => $world->getBlockLight($x, $y, $z) > self::MELT_LIGHT - BlockLight::filter($state) ? BlockFadeEvent::set($world, $x, $y, $z, $registry->getDefaultState(BlockIds::WATER)) : null,
            BlockIds::SNOW_LAYER => $world->getBlockLight($x, $y, $z) > self::MELT_LIGHT ? BlockFadeEvent::set($world, $x, $y, $z, $registry->getDefaultState(BlockIds::AIR)) : null,
            BlockIds::FROSTED_ICE => $this->meltFrostedIce($world, $x, $y, $z, $state),
            default => null
        };
    }

    private function meltFrostedIce(World $world, int $x, int $y, int $z, BlockState $ice) : void{
        $age = $ice->getProperty(BlockProperties::AGE);
        $neighbors = 0;

        foreach([[1, 0, 0], [-1, 0, 0], [0, 1, 0], [0, -1, 0], [0, 0, 1], [0, 0, -1]] as [$dx, $dy, $dz]){
            if(Nearby::name($world, $x + $dx, $y + $dy, $z + $dz) === BlockIds::FROSTED_ICE){
                ++$neighbors;
            }
        }

        if((mt_rand(0, 2) !== 0 && $neighbors >= 4) || $world->getLight($x, $y, $z) <= self::MELT_LIGHT - $age - BlockLight::filter($ice)){
            return;
        }

        if($age < self::FROSTED_ICE_MAX_AGE){
            BlockFadeEvent::set($world, $x, $y, $z, $ice->withProperty(BlockProperties::AGE, $age + 1));
        }else{
            BlockFadeEvent::set($world, $x, $y, $z, Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::WATER));
        }
    }
}