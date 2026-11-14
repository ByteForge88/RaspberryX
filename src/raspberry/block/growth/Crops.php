<?php

declare(strict_types=1);

namespace raspberry\block\growth;

use raspberry\Server;

use raspberry\block\BlockIds;
use raspberry\block\BlockProperties;
use raspberry\block\BlockState;

use raspberry\world\World;

use function in_array;
use function mt_rand;

class Crops implements RandomTickBehavior{

    public const NAMES = [
        "minecraft:wheat",
        "minecraft:carrots",
        "minecraft:potatoes",
        "minecraft:beetroot",
        "minecraft:torchflower_crop",
        "minecraft:pitcher_crop",
        "minecraft:melon_stem",
        "minecraft:pumpkin_stem"
    ];

    private const MAX_GROWTH = 7;

    /** a pitcher crop is two blocks tall from this growth on, and done at the last */
    private const PITCHER_TALL = 3;
    private const PITCHER_DONE = 4;

    /** facing_direction of a stem that holds no fruit */
    private const UNATTACHED = 0;

    /** facing_direction => x, z of the fruit */
    private const STEM_SIDES = [2 => [0, -1], 3 => [0, 1], 4 => [-1, 0], 5 => [1, 0]];

    /** what a stem's fruit may grow on */
    private const FRUIT_GROUND = [
        "minecraft:farmland",
        "minecraft:dirt",
        "minecraft:coarse_dirt",
        "minecraft:grass_block",
        "minecraft:podzol",
        "minecraft:mycelium",
        "minecraft:dirt_with_roots",
        "minecraft:mud",
        "minecraft:moss_block",
        "minecraft:pale_moss_block"
    ];

    public function getBlockNames() : array{
        return self::NAMES;
    }

    public function ticks(BlockState $state) : bool{
        return match($state->getName()){
            "minecraft:melon_stem", "minecraft:pumpkin_stem", "minecraft:torchflower_crop" => true,
            "minecraft:pitcher_crop" => $state->getProperty(BlockProperties::UPPER_BLOCK_BIT) === false && $state->getProperty(BlockProperties::GROWTH) < self::PITCHER_DONE,
            default => $state->getProperty(BlockProperties::GROWTH) < self::MAX_GROWTH
        };
    }

    public function onRandomTick(World $world, int $x, int $y, int $z, BlockState $state) : void{
        $name = $state->getName();
        $stem = $name === "minecraft:melon_stem" || $name === "minecraft:pumpkin_stem";

        if($stem && $this->dropFruit($world, $x, $y, $z, $state)){
            return;
        }

        if(Nearby::rawLight($world, $x, $y, $z) < 9){
            return;
        }

        if(($name === "minecraft:beetroot" || $name === "minecraft:torchflower_crop") && mt_rand(0, 2) === 0){
            return;
        }

        if(mt_rand(0, (int) (25 / self::growthSpeed($world, $x, $y, $z, $name))) !== 0){
            return;
        }

        $growth = $state->getProperty(BlockProperties::GROWTH);

        match(true){
            $stem => $this->growStem($world, $x, $y, $z, $state),
            $name === "minecraft:pitcher_crop" => $this->growPitcher($world, $x, $y, $z, $state),
            $name === "minecraft:torchflower_crop" && $growth >= 1 => $world->setBlock($x, $y, $z, Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::TORCHFLOWER)),
            default => $world->setBlock($x, $y, $z, $state->withProperty(BlockProperties::GROWTH, $growth + 1))
        };
    }

    /**
     * How fast a crop grows: farmland under and around it helps, wet farmland more, and the
     * same crop all around slows it down.
     */
    public static function growthSpeed(World $world, int $x, int $y, int $z, string $name) : float{
        $speed = 1.0;

        for($dx = -1; $dx <= 1; ++$dx){
            for($dz = -1; $dz <= 1; ++$dz){
                $below = Nearby::block($world, $x + $dx, $y - 1, $z + $dz);
                $soil = 0.0;

                if($below !== null && $below->getName() === BlockIds::FARMLAND){
                    $soil = $below->getProperty(BlockProperties::MOISTURIZED_AMOUNT) > 0 ? 3.0 : 1.0;
                }

                $speed += $dx !== 0 || $dz !== 0 ? $soil / 4 : $soil;
            }
        }

        $rows = Nearby::name($world, $x - 1, $y, $z) === $name || Nearby::name($world, $x + 1, $y, $z) === $name;
        $columns = Nearby::name($world, $x, $y, $z - 1) === $name || Nearby::name($world, $x, $y, $z + 1) === $name;

        if($rows && $columns){
            return $speed / 2;
        }

        foreach([[-1, -1], [1, -1], [1, 1], [-1, 1]] as [$dx, $dz]){
            if(Nearby::name($world, $x + $dx, $y, $z + $dz) === $name){
                return $speed / 2;
            }
        }

        return $speed;
    }

    /**
     * A stem lets go of a fruit that is gone. True when it did.
     */
    private function dropFruit(World $world, int $x, int $y, int $z, BlockState $stem) : bool{
        $facing = $stem->getProperty(BlockProperties::FACING_DIRECTION);

        if(!isset(self::STEM_SIDES[$facing])){
            return false;
        }

        [$dx, $dz] = self::STEM_SIDES[$facing];
        $fruit = Nearby::name($world, $x + $dx, $y, $z + $dz);

        if($fruit === "" || $fruit === self::fruitOf($stem->getName())){
            return false;
        }

        $world->setBlock($x, $y, $z, $stem->withProperty(BlockProperties::FACING_DIRECTION, self::UNATTACHED));

        return true;
    }

    private function growStem(World $world, int $x, int $y, int $z, BlockState $stem) : void{
        $growth = $stem->getProperty(BlockProperties::GROWTH);

        if($growth < self::MAX_GROWTH){
            $world->setBlock($x, $y, $z, $stem->withProperty(BlockProperties::GROWTH, $growth + 1));
            return;
        }

        if($stem->getProperty(BlockProperties::FACING_DIRECTION) !== self::UNATTACHED){
            return;
        }

        $facing = mt_rand(2, 5);
        [$dx, $dz] = self::STEM_SIDES[$facing];

        if(!Nearby::isAir($world, $x + $dx, $y, $z + $dz) || !in_array(Nearby::name($world, $x + $dx, $y - 1, $z + $dz), self::FRUIT_GROUND, true)){
            return;
        }

        $world->setBlock($x + $dx, $y, $z + $dz, Server::getInstance()->getBlockStateRegistry()->getDefaultState(self::fruitOf($stem->getName())));
        $world->setBlock($x, $y, $z, $stem->withProperty(BlockProperties::FACING_DIRECTION, $facing));
    }

    private static function fruitOf(string $stem) : string{
        return $stem === "minecraft:melon_stem" ? BlockIds::MELON_BLOCK : BlockIds::PUMPKIN;
    }

    private function growPitcher(World $world, int $x, int $y, int $z, BlockState $crop) : void{
        $growth = $crop->getProperty(BlockProperties::GROWTH) + 1;

        if($growth >= self::PITCHER_TALL){
            $above = Nearby::block($world, $x, $y + 1, $z);

            if($above === null || ($above->getName() !== BlockIds::AIR && $above->getName() !== "minecraft:pitcher_crop")){
                return;
            }

            $world->setBlock($x, $y + 1, $z, $crop->withProperty(BlockProperties::GROWTH, $growth)->withProperty(BlockProperties::UPPER_BLOCK_BIT, true));
        }

        $world->setBlock($x, $y, $z, $crop->withProperty(BlockProperties::GROWTH, $growth));
    }
}