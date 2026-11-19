<?php

declare(strict_types=1);

namespace raspberry\block\growth;

use raspberry\Server;

use raspberry\block\BlockIds;
use raspberry\block\BlockProperties;
use raspberry\block\BlockState;

use raspberry\entity\Entity;

use raspberry\math\Vector3;

use raspberry\player\Player;

use raspberry\world\GameRules;
use raspberry\world\World;

use raspberry\event\block\BlockFadeEvent;
use raspberry\event\entity\EntityTrampleFarmlandEvent;

use function in_array;
use function mt_getrandmax;
use function mt_rand;

class Farmland implements RandomTickBehavior{

    private const int WET = 7;

    private const int WATER_REACH = 4;

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

    public static function trample(World $world, int $x, int $y, int $z, Entity $entity, float $fall) : void{
        if($fall <= 0.5 || mt_rand() / mt_getrandmax() >= $fall - 0.5 || $world->getBlock($x, $y, $z)->getName() !== BlockIds::FARMLAND){
            return;
        }

        if(!$entity instanceof Player && !$world->getGameRules()->getBool(GameRules::MOB_GRIEFING)){
            return;
        }

        if($entity->getWidth() * $entity->getWidth() * $entity->getHeight() <= 0.512){
            return;
        }

        if(EntityTrampleFarmlandEvent::hasHandlers()){
            $event = new EntityTrampleFarmlandEvent($entity, new Vector3($x, $y, $z));
            $event->call();

            if($event->isCancelled()){
                return;
            }
        }

        $world->setBlock($x, $y, $z, Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::DIRT));
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