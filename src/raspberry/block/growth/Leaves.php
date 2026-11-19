<?php

declare(strict_types=1);

namespace raspberry\block\growth;

use raspberry\Server;

use raspberry\block\BlockBreakInfo;
use raspberry\block\BlockIds;
use raspberry\block\BlockProperties;
use raspberry\block\BlockState;

use raspberry\item\Item;

use raspberry\math\Vector3;

use raspberry\world\World;

use raspberry\event\block\LeavesDecayEvent;

use SplQueue;

use function preg_match;

class Leaves implements RandomTickBehavior{

    private const int REACH = 4;

    private const int LOG_MARK_RADIUS = 4;

    public function getBlockNames() : array{
        $names = [];

        foreach(Server::getInstance()->getBlockStateRegistry()->getBlockNames() as $name){
            if(self::isLeaves($name)){
                $names[] = $name;
            }
        }

        return $names;
    }

    public function ticks(BlockState $state) : bool{
        return $state->getProperty(BlockProperties::PERSISTENT_BIT) === false && $state->getProperty(BlockProperties::UPDATE_BIT) === true;
    }

    public function onRandomTick(World $world, int $x, int $y, int $z, BlockState $state) : void{
        if(self::hasLogNear($world, $x, $y, $z)){
            $world->setBlock($x, $y, $z, $state->withProperty(BlockProperties::UPDATE_BIT, false));
            return;
        }

        if(!LeavesDecayEvent::set($world, $x, $y, $z, Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::AIR))){
            return;
        }

        $world->setBlock($x, $y, $z, Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::AIR));

        foreach(BlockBreakInfo::get($state->getName())->getDrops($state, Item::air()) as $item){
            $world->dropItem(new Vector3($x + 0.5, $y + 0.5, $z + 0.5), $item);
        }
    }

    public static function isLeaves(string $name) : bool{
        return preg_match('/^minecraft:\w+_leaves(_flowered)?$/D', $name) === 1;
    }

    public static function isLog(string $name) : bool{
        return preg_match('/^minecraft:(stripped_)?\w+_(log|wood)$/D', $name) === 1;
    }

    public static function onBlockChanged(World $world, int $x, int $y, int $z, BlockState $old, BlockState $new) : void{
        $name = $old->getName();

        if($name === $new->getName()){
            return;
        }

        $radius = match(true){
            self::isLog($name) => self::LOG_MARK_RADIUS,
            self::isLeaves($name) => 1,
            default => 0
        };

        if($radius === 0){
            return;
        }

        for($dx = -$radius; $dx <= $radius; ++$dx){
            for($dy = -$radius; $dy <= $radius; ++$dy){
                for($dz = -$radius; $dz <= $radius; ++$dz){
                    $leaves = Nearby::block($world, $x + $dx, $y + $dy, $z + $dz);

                    if($leaves !== null && self::isLeaves($leaves->getName()) && $leaves->getProperty(BlockProperties::PERSISTENT_BIT) === false && $leaves->getProperty(BlockProperties::UPDATE_BIT) === false){
                        $world->setBlock($x + $dx, $y + $dy, $z + $dz, $leaves->withProperty(BlockProperties::UPDATE_BIT, true));
                    }
                }
            }
        }
    }

    private static function hasLogNear(World $world, int $x, int $y, int $z) : bool{
        /** @var SplQueue<array{int, int, int, int}> $queue */
        $queue = new SplQueue();
        $queue->enqueue([$x, $y, $z, 0]);
        $seen = [$x . ":" . $y . ":" . $z => true];

        while(!$queue->isEmpty()){
            [$cx, $cy, $cz, $distance] = $queue->dequeue();

            foreach([[1, 0, 0], [-1, 0, 0], [0, 1, 0], [0, -1, 0], [0, 0, 1], [0, 0, -1]] as [$dx, $dy, $dz]){
                [$nx, $ny, $nz] = [$cx + $dx, $cy + $dy, $cz + $dz];
                $key = $nx . ":" . $ny . ":" . $nz;

                if(isset($seen[$key])){
                    continue;
                }

                $seen[$key] = true;
                $name = Nearby::name($world, $nx, $ny, $nz);

                if(self::isLog($name)){
                    return true;
                }

                if($distance + 1 < self::REACH && self::isLeaves($name)){
                    $queue->enqueue([$nx, $ny, $nz, $distance + 1]);
                }
            }
        }

        return false;
    }
}