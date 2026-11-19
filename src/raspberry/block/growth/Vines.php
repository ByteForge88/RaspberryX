<?php

declare(strict_types=1);

namespace raspberry\block\growth;

use raspberry\Server;

use raspberry\block\BlockCollision;
use raspberry\block\BlockIds;
use raspberry\block\BlockProperties;
use raspberry\block\BlockState;

use raspberry\event\block\BlockGrowEvent;
use raspberry\event\block\BlockSpreadEvent;

use raspberry\math\Facing;
use raspberry\math\Vector3;

use raspberry\world\Chunk;
use raspberry\world\World;

use function mt_rand;

class Vines implements RandomTickBehavior{

    public const array BITS = [Facing::SOUTH => 1, Facing::WEST => 2, Facing::NORTH => 4, Facing::EAST => 8];

    private const int CROWD = 5;

    public function getBlockNames() : array{
        return [BlockIds::VINE];
    }

    public function ticks(BlockState $state) : bool{
        return true;
    }

    public function onRandomTick(World $world, int $x, int $y, int $z, BlockState $state) : void{
        if(mt_rand(0, 3) !== 0){
            return;
        }

        $bits = (int) $state->getProperty(BlockProperties::VINE_DIRECTION_BITS);
        $direction = Facing::ALL[mt_rand(0, 5)];

        if(Facing::isHorizontal($direction) && ($bits & self::BITS[$direction]) === 0){
            if(self::isCrowded($world, $x, $y, $z)){
                return;
            }

            [$dx, , $dz] = Facing::offset($direction);
            [$tx, $tz] = [$x + $dx, $z + $dz];
            $target = Nearby::block($world, $tx, $y, $tz);

            if($target === null){
                return;
            }

            if($target->getName() !== BlockIds::AIR){
                if(self::canLeanOn($world, $tx, $y, $tz)){
                    BlockGrowEvent::set($world, $x, $y, $z, self::withBits($bits | self::BITS[$direction]));
                }

                return;
            }

            $right = Facing::rotateRight($direction);
            $left = Facing::rotateLeft($direction);

            foreach([$right, $left] as $side){
                [$sx, , $sz] = Facing::offset($side);

                if(($bits & self::BITS[$side]) !== 0 && self::canLeanOn($world, $tx + $sx, $y, $tz + $sz)){
                    BlockSpreadEvent::spread($world, $tx, $y, $tz, self::withBits(self::BITS[$side]), new Vector3($x, $y, $z));
                    return;
                }
            }

            foreach([$right, $left] as $side){
                [$sx, , $sz] = Facing::offset($side);

                if(($bits & self::BITS[$side]) !== 0 && Nearby::isAir($world, $tx + $sx, $y, $tz + $sz) && self::canLeanOn($world, $x + $sx, $y, $z + $sz)){
                    BlockSpreadEvent::spread($world, $tx + $sx, $y, $tz + $sz, self::withBits(self::BITS[Facing::opposite($direction)]), new Vector3($x, $y, $z));
                    return;
                }
            }

            return;
        }

        if($direction === Facing::UP){
            if($y < Chunk::MAX_Y && Nearby::isAir($world, $x, $y + 1, $z) && !self::isCrowded($world, $x, $y, $z)){
                $up = 0;

                foreach(self::BITS as $face => $bit){
                    [$dx, , $dz] = Facing::offset($face);

                    if(($bits & $bit) !== 0 && mt_rand(0, 1) === 0 && self::canLeanOn($world, $x + $dx, $y + 1, $z + $dz)){
                        $up |= $bit;
                    }
                }

                if($up !== 0){
                    BlockSpreadEvent::spread($world, $x, $y + 1, $z, self::withBits($up), new Vector3($x, $y, $z));
                }
            }

            return;
        }

        if($y <= Chunk::MIN_Y){
            return;
        }

        $below = Nearby::block($world, $x, $y - 1, $z);

        if($below === null || ($below->getName() !== BlockIds::AIR && $below->getName() !== BlockIds::VINE)){
            return;
        }

        $old = $below->getName() === BlockIds::VINE ? (int) $below->getProperty(BlockProperties::VINE_DIRECTION_BITS) : 0;
        $new = $old;

        foreach(self::BITS as $bit){
            if(($bits & $bit) !== 0 && mt_rand(0, 1) === 0){
                $new |= $bit;
            }
        }

        if($new !== $old && $new !== 0){
            BlockSpreadEvent::spread($world, $x, $y - 1, $z, self::withBits($new), new Vector3($x, $y, $z));
        }
    }

    public static function getHeldBits(World $world, int $x, int $y, int $z, int $bits) : int{
        $above = $y < Chunk::MAX_Y ? Nearby::block($world, $x, $y + 1, $z) : null;
        $aboveBits = $above !== null && $above->getName() === BlockIds::VINE ? (int) $above->getProperty(BlockProperties::VINE_DIRECTION_BITS) : 0;
        $held = 0;

        foreach(self::BITS as $face => $bit){
            if(($bits & $bit) === 0){
                continue;
            }

            [$dx, , $dz] = Facing::offset($face);

            if(($aboveBits & $bit) !== 0 || self::canLeanOn($world, $x + $dx, $y, $z + $dz)){
                $held |= $bit;
            }
        }

        return $held;
    }

    public static function withBits(int $bits) : BlockState{
        return Server::getInstance()->getBlockStateRegistry()->get(BlockIds::VINE, [BlockProperties::VINE_DIRECTION_BITS => $bits]);
    }

    private static function canLeanOn(World $world, int $x, int $y, int $z) : bool{
        $block = Nearby::block($world, $x, $y, $z);
        return $block === null || ($block->getName() !== BlockIds::VINE && BlockCollision::isFullCube($block));
    }

    private static function isCrowded(World $world, int $x, int $y, int $z) : bool{
        $left = self::CROWD;

        for($dx = -4; $dx <= 4; ++$dx){
            for($dz = -4; $dz <= 4; ++$dz){
                for($dy = -1; $dy <= 1; ++$dy){
                    if(Nearby::name($world, $x + $dx, $y + $dy, $z + $dz) === BlockIds::VINE && --$left <= 0){
                        return true;
                    }
                }
            }
        }

        return false;
    }
}