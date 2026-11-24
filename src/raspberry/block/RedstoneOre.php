<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\Server;

use raspberry\world\World;

class RedstoneOre{

    private const array LIT = [
        BlockIds::REDSTONE_ORE => BlockIds::LIT_REDSTONE_ORE,
        BlockIds::DEEPSLATE_REDSTONE_ORE => BlockIds::LIT_DEEPSLATE_REDSTONE_ORE
    ];

    private function __construct(){
    }

    public static function isUnlit(string $name) : bool{
        return isset(self::LIT[$name]);
    }

    public static function isOre(string $name) : bool{
        return isset(self::LIT[$name]) || $name === BlockIds::LIT_REDSTONE_ORE || $name === BlockIds::LIT_DEEPSLATE_REDSTONE_ORE;
    }

    public static function light(World $world, int $x, int $y, int $z) : void{
        $name = $world->getBlock($x, $y, $z)->getName();

        if(isset(self::LIT[$name])){
            $world->setBlock($x, $y, $z, Server::getInstance()->getBlockStateRegistry()->getDefaultState(self::LIT[$name]));
        }
    }
}