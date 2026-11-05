<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\math\Facing;

use raspberry\world\World;

class StairsShape{

    private function __construct(){
    }

    /**
     * Updates the corners of the stairs at a position and of the stairs beside it.
     */
    public static function updateAround(World $world, int $x, int $y, int $z) : void{
        self::update($world, $x, $y, $z);

        foreach(Facing::HORIZONTAL as $side){
            [$dx, , $dz] = Facing::offset($side);
            self::update($world, $x + $dx, $y, $z + $dz);
        }
    }

    private static function update(World $world, int $x, int $y, int $z) : void{
        if(!$world->isChunkLoaded($x >> 4, $z >> 4)){
            return;
        }

        $block = $world->getBlock($x, $y, $z);

        if(!$block->hasProperty(BlockProperties::MINECRAFT_CORNER)){
            return;
        }

        $corner = self::getCorner($world, $x, $y, $z, $block);

        if($block->getProperty(BlockProperties::MINECRAFT_CORNER) !== $corner){
            $world->setBlock($x, $y, $z, $block->withProperty(BlockProperties::MINECRAFT_CORNER, $corner));
        }
    }

    /**
     * The corner stairs make with the stairs in front of and behind them.
     */
    public static function getCorner(World $world, int $x, int $y, int $z, BlockState $stairs) : string{
        $facing = self::getFacing($stairs);
        $right = Facing::rotateRight($facing);

        $front = self::getStairs($world, $x, $y, $z, $facing, $stairs);

        if($front !== null){
            if(self::getFacing($front) === $right){
                return "outer_right";
            }

            if(self::getFacing($front) === Facing::opposite($right)){
                return self::isContinued($world, $x, $y, $z, $stairs) ? "none" : "outer_left";
            }
        }

        $back = self::getStairs($world, $x, $y, $z, Facing::opposite($facing), $stairs);

        if($back !== null){
            if(self::getFacing($back) === $right && !self::isContinued($world, $x, $y, $z, $stairs)){
                return "inner_right";
            }

            if(self::getFacing($back) === Facing::opposite($right)){
                return "inner_left";
            }
        }

        return "none";
    }

    private static function getFacing(BlockState $stairs) : int{
        return BlockOrientation::fromWeirdoDirection((int) $stairs->getProperty(BlockProperties::WEIRDO_DIRECTION));
    }

    private static function getStairs(World $world, int $x, int $y, int $z, int $side, BlockState $stairs) : ?BlockState{
        [$dx, , $dz] = Facing::offset($side);

        if(!$world->isChunkLoaded(($x + $dx) >> 4, ($z + $dz) >> 4)){
            return null;
        }

        $block = $world->getBlock($x + $dx, $y, $z + $dz);

        if(!$block->hasProperty(BlockProperties::WEIRDO_DIRECTION) || $block->getProperty(BlockProperties::UPSIDE_DOWN_BIT) !== $stairs->getProperty(BlockProperties::UPSIDE_DOWN_BIT)){
            return null;
        }

        return $block;
    }

    private static function isContinued(World $world, int $x, int $y, int $z, BlockState $stairs) : bool{
        $side = self::getStairs($world, $x, $y, $z, Facing::rotateRight(self::getFacing($stairs)), $stairs);

        return $side !== null && self::getFacing($side) === self::getFacing($stairs);
    }
}