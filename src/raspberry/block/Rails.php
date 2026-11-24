<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\Server;

use raspberry\block\redstone\RedstoneSignal;

use raspberry\entity\object\Cart;

use raspberry\world\Chunk;
use raspberry\world\World;

use function in_array;

class Rails{

    public const int NORTH_SOUTH = 0;
    public const int EAST_WEST = 1;
    public const int ASCENDING_EAST = 2;
    public const int ASCENDING_WEST = 3;
    public const int ASCENDING_NORTH = 4;
    public const int ASCENDING_SOUTH = 5;
    public const int SOUTH_EAST = 6;
    public const int SOUTH_WEST = 7;
    public const int NORTH_WEST = 8;
    public const int NORTH_EAST = 9;

    public const array EXITS = [
        self::NORTH_SOUTH => [[0, 0, -1], [0, 0, 1]],
        self::EAST_WEST => [[-1, 0, 0], [1, 0, 0]],
        self::ASCENDING_EAST => [[-1, -1, 0], [1, 0, 0]],
        self::ASCENDING_WEST => [[-1, 0, 0], [1, -1, 0]],
        self::ASCENDING_NORTH => [[0, 0, -1], [0, -1, 1]],
        self::ASCENDING_SOUTH => [[0, -1, -1], [0, 0, 1]],
        self::SOUTH_EAST => [[0, 0, 1], [1, 0, 0]],
        self::SOUTH_WEST => [[0, 0, 1], [-1, 0, 0]],
        self::NORTH_WEST => [[0, 0, -1], [-1, 0, 0]],
        self::NORTH_EAST => [[0, 0, -1], [1, 0, 0]]
    ];

    public const array CONNECTIONS = [
        self::NORTH_SOUTH => [[0, 0, -1], [0, 0, 1]],
        self::EAST_WEST => [[-1, 0, 0], [1, 0, 0]],
        self::ASCENDING_EAST => [[-1, 0, 0], [1, 1, 0]],
        self::ASCENDING_WEST => [[-1, 1, 0], [1, 0, 0]],
        self::ASCENDING_NORTH => [[0, 1, -1], [0, 0, 1]],
        self::ASCENDING_SOUTH => [[0, 0, -1], [0, 1, 1]],
        self::SOUTH_EAST => [[1, 0, 0], [0, 0, 1]],
        self::SOUTH_WEST => [[-1, 0, 0], [0, 0, 1]],
        self::NORTH_WEST => [[-1, 0, 0], [0, 0, -1]],
        self::NORTH_EAST => [[1, 0, 0], [0, 0, -1]]
    ];

    public const int POWER_REACH = 8;

    public const int DETECTOR_TICKS = 20;

    private function __construct(){
    }

    public static function isRail(string $name) : bool{
        return $name === BlockIds::RAIL || $name === BlockIds::GOLDEN_RAIL || $name === BlockIds::DETECTOR_RAIL || $name === BlockIds::ACTIVATOR_RAIL;
    }

    public static function isStraight(string $name) : bool{
        return $name !== BlockIds::RAIL;
    }

    public static function shapeOf(BlockState $rail) : int{
        $shape = $rail->hasProperty(BlockProperties::RAIL_DIRECTION) ? (int) $rail->getProperty(BlockProperties::RAIL_DIRECTION) : self::NORTH_SOUTH;

        return isset(self::EXITS[$shape]) ? $shape : self::NORTH_SOUTH;
    }

    public static function isAscending(int $shape) : bool{
        return $shape >= self::ASCENDING_EAST && $shape <= self::ASCENDING_SOUTH;
    }

    public static function isPowered(BlockState $rail) : bool{
        return $rail->getName() !== BlockIds::RAIL && $rail->getProperty(BlockProperties::RAIL_DATA_BIT) === true;
    }

    public static function blockAt(World $world, int $x, int $y, int $z) : BlockState{
        if($y < Chunk::MIN_Y || $y > Chunk::MAX_Y || !$world->isChunkLoaded($x >> 4, $z >> 4)){
            return Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::AIR);
        }

        return $world->getBlock($x, $y, $z);
    }

    public static function isRailAt(World $world, int $x, int $y, int $z) : bool{
        return self::isRail(self::blockAt($world, $x, $y, $z)->getName());
    }

    public static function canStay(World $world, int $x, int $y, int $z, BlockState $rail) : bool{
        if(!BlockCollision::isSolid(self::blockAt($world, $x, $y - 1, $z))){
            return false;
        }

        return match(self::shapeOf($rail)){
            self::ASCENDING_EAST => BlockCollision::isSolid(self::blockAt($world, $x + 1, $y, $z)),
            self::ASCENDING_WEST => BlockCollision::isSolid(self::blockAt($world, $x - 1, $y, $z)),
            self::ASCENDING_NORTH => BlockCollision::isSolid(self::blockAt($world, $x, $y, $z - 1)),
            self::ASCENDING_SOUTH => BlockCollision::isSolid(self::blockAt($world, $x, $y, $z + 1)),
            default => true
        };
    }

    public static function receivesPower(World $world, RedstoneSignal $signal, int $x, int $y, int $z, BlockState $rail) : bool{
        return $signal->getReceivedPower($x, $y, $z) > 0 ||
            self::findPower($world, $signal, $x, $y, $z, $rail, true, 0) ||
            self::findPower($world, $signal, $x, $y, $z, $rail, false, 0);
    }

    private static function findPower(World $world, RedstoneSignal $signal, int $x, int $y, int $z, BlockState $rail, bool $forward, int $distance) : bool{
        if($distance >= self::POWER_REACH){
            return false;
        }

        $shape = self::shapeOf($rail);
        $lookBelow = true;

        switch($shape){
            case self::NORTH_SOUTH:
                $z += $forward ? 1 : -1;
                break;
            case self::EAST_WEST:
                $x += $forward ? -1 : 1;
                break;
            case self::ASCENDING_EAST:
                if($forward){
                    --$x;
                }else{
                    ++$x;
                    ++$y;
                    $lookBelow = false;
                }

                $shape = self::EAST_WEST;
                break;
            case self::ASCENDING_WEST:
                if($forward){
                    --$x;
                    ++$y;
                    $lookBelow = false;
                }else{
                    ++$x;
                }

                $shape = self::EAST_WEST;
                break;
            case self::ASCENDING_NORTH:
                if($forward){
                    ++$z;
                }else{
                    --$z;
                    ++$y;
                    $lookBelow = false;
                }

                $shape = self::NORTH_SOUTH;
                break;
            case self::ASCENDING_SOUTH:
                if($forward){
                    ++$z;
                    ++$y;
                    $lookBelow = false;
                }else{
                    --$z;
                }

                $shape = self::NORTH_SOUTH;
                break;
        }

        return self::isPoweredInLine($world, $signal, $x, $y, $z, $rail->getName(), $forward, $distance, $shape) ||
            ($lookBelow && self::isPoweredInLine($world, $signal, $x, $y - 1, $z, $rail->getName(), $forward, $distance, $shape));
    }

    private static function isPoweredInLine(World $world, RedstoneSignal $signal, int $x, int $y, int $z, string $name, bool $forward, int $distance, int $shape) : bool{
        $rail = self::blockAt($world, $x, $y, $z);

        if($rail->getName() !== $name || !self::isPowered($rail)){
            return false;
        }

        $other = self::shapeOf($rail);

        if($shape === self::EAST_WEST && in_array($other, [self::NORTH_SOUTH, self::ASCENDING_NORTH, self::ASCENDING_SOUTH], true)){
            return false;
        }

        if($shape === self::NORTH_SOUTH && in_array($other, [self::EAST_WEST, self::ASCENDING_EAST, self::ASCENDING_WEST], true)){
            return false;
        }

        return $signal->getReceivedPower($x, $y, $z) > 0 || self::findPower($world, $signal, $x, $y, $z, $rail, $forward, $distance + 1);
    }

    /**
     * @return list<Cart>
     */
    public static function findCarts(World $world, int $x, int $y, int $z) : array{
        $carts = [];

        foreach($world->getEntities() as $entity){
            if($entity instanceof Cart && !$entity->isClosed() && $entity->overlaps($x + 0.2, $y, $z + 0.2, $x + 0.8, $y + 0.8, $z + 0.8)){
                $carts[] = $entity;
            }
        }

        return $carts;
    }

    public static function sense(World $world, int $x, int $y, int $z) : void{
        $rail = self::blockAt($world, $x, $y, $z);

        if($rail->getName() !== BlockIds::DETECTOR_RAIL){
            return;
        }

        $pressed = self::findCarts($world, $x, $y, $z) !== [];

        if($pressed !== self::isPowered($rail)){
            $world->setBlock($x, $y, $z, $rail->withProperty(BlockProperties::RAIL_DATA_BIT, $pressed));
        }

        if($pressed){
            $world->getRedstoneUpdater()->update($x, $y, $z);
            $world->scheduleUpdate(self::DETECTOR_TICKS, fn() => self::sense($world, $x, $y, $z), $x, $z);
        }
    }
}