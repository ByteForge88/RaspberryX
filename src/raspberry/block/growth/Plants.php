<?php

declare(strict_types=1);

namespace raspberry\block\growth;

use raspberry\Server;

use raspberry\block\BlockProperties;
use raspberry\block\BlockState;

use raspberry\world\World;
use raspberry\world\light\BlockLight;

use raspberry\event\block\BlockGrowEvent;

use function in_array;
use function mt_getrandmax;
use function mt_rand;

class Plants implements RandomTickBehavior{

    private const string SUGAR_CANE = "minecraft:reeds";
    private const string CACTUS = "minecraft:cactus";
    private const string CACTUS_FLOWER = "minecraft:cactus_flower";
    private const string BAMBOO = "minecraft:bamboo";
    private const string BAMBOO_SAPLING = "minecraft:bamboo_sapling";
    private const string KELP = "minecraft:kelp";
    private const string CAVE_VINES = "minecraft:cave_vines";
    private const string CAVE_VINES_HEAD_BERRIES = "minecraft:cave_vines_head_with_berries";
    private const string CAVE_VINES_BODY_BERRIES = "minecraft:cave_vines_body_with_berries";
    private const string TWISTING_VINES = "minecraft:twisting_vines";
    private const string WEEPING_VINES = "minecraft:weeping_vines";
    private const string NETHER_WART = "minecraft:nether_wart";
    private const string SWEET_BERRY_BUSH = "minecraft:sweet_berry_bush";
    private const string COCOA = "minecraft:cocoa";
    private const string BROWN_MUSHROOM = "minecraft:brown_mushroom";
    private const string RED_MUSHROOM = "minecraft:red_mushroom";

    /** sugar cane and cactus stop at this many blocks */
    private const int STACK_HEIGHT = 3;

    private const int MAX_AGE = 15;

    private const int VINE_MAX_AGE = 25;

    private const int BAMBOO_MAX_HEIGHT = 16;

    /** what mushrooms grow on in any light */
    private const array MUSHROOM_GROUND = ["minecraft:mycelium", "minecraft:podzol", "minecraft:crimson_nylium", "minecraft:warped_nylium"];

    public function getBlockNames() : array{
        return [
            self::SUGAR_CANE, self::CACTUS, self::BAMBOO, self::BAMBOO_SAPLING, self::KELP, self::CAVE_VINES, self::CAVE_VINES_HEAD_BERRIES,
            self::TWISTING_VINES, self::WEEPING_VINES, self::NETHER_WART, self::SWEET_BERRY_BUSH, self::COCOA, self::BROWN_MUSHROOM, self::RED_MUSHROOM
        ];
    }

    public function ticks(BlockState $state) : bool{
        return match($state->getName()){
            self::BAMBOO => $state->getProperty(BlockProperties::AGE_BIT) === false,
            self::KELP => $state->getProperty(BlockProperties::KELP_AGE) < self::VINE_MAX_AGE,
            self::CAVE_VINES, self::CAVE_VINES_HEAD_BERRIES => $state->getProperty(BlockProperties::GROWING_PLANT_AGE) < self::VINE_MAX_AGE,
            self::TWISTING_VINES => $state->getProperty(BlockProperties::TWISTING_VINES_AGE) < self::VINE_MAX_AGE,
            self::WEEPING_VINES => $state->getProperty(BlockProperties::WEEPING_VINES_AGE) < self::VINE_MAX_AGE,
            self::NETHER_WART => $state->getProperty(BlockProperties::AGE) < 3,
            self::SWEET_BERRY_BUSH => $state->getProperty(BlockProperties::GROWTH) < 3,
            self::COCOA => $state->getProperty(BlockProperties::AGE) < 2,
            default => true
        };
    }

    public function onRandomTick(World $world, int $x, int $y, int $z, BlockState $state) : void{
        match($state->getName()){
            self::SUGAR_CANE => $this->growSugarCane($world, $x, $y, $z, $state),
            self::CACTUS => $this->growCactus($world, $x, $y, $z, $state),
            self::BAMBOO => $this->growBamboo($world, $x, $y, $z, $state),
            self::BAMBOO_SAPLING => $this->growBambooSapling($world, $x, $y, $z),
            self::KELP => $this->growKelp($world, $x, $y, $z, $state),
            self::CAVE_VINES, self::CAVE_VINES_HEAD_BERRIES => $this->growCaveVines($world, $x, $y, $z, $state),
            self::TWISTING_VINES => $this->growNetherVines($world, $x, $y, $z, $state, BlockProperties::TWISTING_VINES_AGE, 1),
            self::WEEPING_VINES => $this->growNetherVines($world, $x, $y, $z, $state, BlockProperties::WEEPING_VINES_AGE, -1),
            self::NETHER_WART => mt_rand(0, 9) === 0 ? $this->addAge($world, $x, $y, $z, $state, BlockProperties::AGE) : null,
            self::SWEET_BERRY_BUSH => mt_rand(0, 4) === 0 && Nearby::rawLight($world, $x, $y + 1, $z) >= 9 ? $this->addAge($world, $x, $y, $z, $state, BlockProperties::GROWTH) : null,
            self::COCOA => mt_rand(0, 4) === 0 ? $this->addAge($world, $x, $y, $z, $state, BlockProperties::AGE) : null,
            self::BROWN_MUSHROOM, self::RED_MUSHROOM => $this->spreadMushroom($world, $x, $y, $z, $state),
            default => null
        };
    }

    private function addAge(World $world, int $x, int $y, int $z, BlockState $state, string $property) : void{
        BlockGrowEvent::set($world, $x, $y, $z, $state->withProperty($property, $state->getProperty($property) + 1));
    }

    private function state(string $name) : BlockState{
        return Server::getInstance()->getBlockStateRegistry()->getDefaultState($name);
    }

    /**
     * How many of the same block are stacked from this one down, this one counted.
     */
    private function stackHeight(World $world, int $x, int $y, int $z, string $name, int $max) : int{
        $height = 1;

        while($height < $max && Nearby::name($world, $x, $y - $height, $z) === $name){
            ++$height;
        }

        return $height;
    }

    private function growSugarCane(World $world, int $x, int $y, int $z, BlockState $cane) : void{
        if(!Nearby::isAir($world, $x, $y + 1, $z) || $this->stackHeight($world, $x, $y, $z, self::SUGAR_CANE, self::STACK_HEIGHT) >= self::STACK_HEIGHT){
            return;
        }

        $age = $cane->getProperty(BlockProperties::AGE);

        if($age === self::MAX_AGE){
            BlockGrowEvent::set($world, $x, $y + 1, $z, $this->state(self::SUGAR_CANE));
            BlockGrowEvent::set($world, $x, $y, $z, $cane->withProperty(BlockProperties::AGE, 0));
        }else{
            BlockGrowEvent::set($world, $x, $y, $z, $cane->withProperty(BlockProperties::AGE, $age + 1));
        }
    }

    private function growCactus(World $world, int $x, int $y, int $z, BlockState $cactus) : void{
        if(!Nearby::isAir($world, $x, $y + 1, $z)){
            return;
        }

        $age = $cactus->getProperty(BlockProperties::AGE);
        $height = $this->stackHeight($world, $x, $y, $z, self::CACTUS, self::STACK_HEIGHT);

        if($height >= self::STACK_HEIGHT && $age === self::MAX_AGE){
            return;
        }

        if($age === 8 && $this->cactusFits($world, $x, $y + 1, $z)){
            //halfway grown, a cactus may flower instead, a tall one more often
            if(mt_rand() / mt_getrandmax() <= ($height >= self::STACK_HEIGHT ? 0.25 : 0.1)){
                BlockGrowEvent::set($world, $x, $y + 1, $z, $this->state(self::CACTUS_FLOWER));
            }
        }elseif($age === self::MAX_AGE && $height < self::STACK_HEIGHT){
            if($this->cactusFits($world, $x, $y + 1, $z)){
                BlockGrowEvent::set($world, $x, $y + 1, $z, $this->state(self::CACTUS));
            }

            BlockGrowEvent::set($world, $x, $y, $z, $cactus->withProperty(BlockProperties::AGE, 0));

            return;
        }

        if($age < self::MAX_AGE){
            BlockGrowEvent::set($world, $x, $y, $z, $cactus->withProperty(BlockProperties::AGE, $age + 1));
        }
    }

    /**
     * A cactus needs nothing at its sides.
     */
    private function cactusFits(World $world, int $x, int $y, int $z) : bool{
        foreach(Nearby::HORIZONTAL as [$dx, , $dz]){
            if(!Nearby::isAir($world, $x + $dx, $y, $z + $dz)){
                return false;
            }
        }

        return true;
    }

    private function growBamboo(World $world, int $x, int $y, int $z, BlockState $bamboo) : void{
        if(mt_rand(0, 2) !== 0 || !Nearby::isAir($world, $x, $y + 1, $z) || Nearby::rawLight($world, $x, $y + 1, $z) < 9){
            return;
        }

        $height = $this->stackHeight($world, $x, $y, $z, self::BAMBOO, self::BAMBOO_MAX_HEIGHT + 1);

        if($height >= self::BAMBOO_MAX_HEIGHT){
            return;
        }

        $below = Nearby::block($world, $x, $y - 1, $z);
        $belowTwo = Nearby::block($world, $x, $y - 2, $z);
        $twoBamboo = $belowTwo?->getName() === self::BAMBOO;

        //the newest stalk gets the big leaves, the ones under it less and then none
        if($below?->getName() !== self::BAMBOO || $below->getProperty(BlockProperties::BAMBOO_LEAF_SIZE) === "no_leaves"){
            $leaves = "small_leaves";
        }else{
            $leaves = "large_leaves";

            if($twoBamboo){
                BlockGrowEvent::set($world, $x, $y - 1, $z, $below->withProperty(BlockProperties::BAMBOO_LEAF_SIZE, "small_leaves"));
                BlockGrowEvent::set($world, $x, $y - 2, $z, $belowTwo->withProperty(BlockProperties::BAMBOO_LEAF_SIZE, "no_leaves"));
            }
        }

        $thick = $bamboo->getProperty(BlockProperties::BAMBOO_STALK_THICKNESS) === "thick" || $twoBamboo;
        $done = ($height >= 11 && mt_rand(0, 3) === 0) || $height === 15;

        BlockGrowEvent::set($world, $x, $y + 1, $z, $this->state(self::BAMBOO)
            ->withProperty(BlockProperties::BAMBOO_STALK_THICKNESS, $thick ? "thick" : "thin")
            ->withProperty(BlockProperties::BAMBOO_LEAF_SIZE, $leaves)
            ->withProperty(BlockProperties::AGE_BIT, $done));
    }

    private function growBambooSapling(World $world, int $x, int $y, int $z) : void{
        if(mt_rand(0, 2) !== 0 || !Nearby::isAir($world, $x, $y + 1, $z) || Nearby::rawLight($world, $x, $y + 1, $z) < 9){
            return;
        }

        $bamboo = $this->state(self::BAMBOO);
        BlockGrowEvent::set($world, $x, $y + 1, $z, $bamboo->withProperty(BlockProperties::BAMBOO_LEAF_SIZE, "small_leaves"));
        BlockGrowEvent::set($world, $x, $y, $z, $bamboo);
    }

    private function growKelp(World $world, int $x, int $y, int $z, BlockState $kelp) : void{
        if(mt_rand(0, 99) >= 14 || !Nearby::isWaterSource(Nearby::block($world, $x, $y + 1, $z))){
            return;
        }

        BlockGrowEvent::set($world, $x, $y + 1, $z, $kelp->withProperty(BlockProperties::KELP_AGE, $kelp->getProperty(BlockProperties::KELP_AGE) + 1));
    }

    private function growCaveVines(World $world, int $x, int $y, int $z, BlockState $vines) : void{
        if(mt_rand(0, 9) !== 0 || !Nearby::isAir($world, $x, $y - 1, $z)){
            return;
        }

        $age = $vines->getProperty(BlockProperties::GROWING_PLANT_AGE);
        $next = mt_rand(0, 99) < 11 ? self::CAVE_VINES_HEAD_BERRIES : self::CAVE_VINES;

        BlockGrowEvent::set($world, $x, $y - 1, $z, $this->state($next)->withProperty(BlockProperties::GROWING_PLANT_AGE, $age + 1));

        if($vines->getName() === self::CAVE_VINES_HEAD_BERRIES){
            BlockGrowEvent::set($world, $x, $y, $z, $this->state(self::CAVE_VINES_BODY_BERRIES)->withProperty(BlockProperties::GROWING_PLANT_AGE, $age));
        }
    }

    private function growNetherVines(World $world, int $x, int $y, int $z, BlockState $vines, string $property, int $direction) : void{
        if(mt_rand(0, 9) !== 0 || !Nearby::isAir($world, $x, $y + $direction, $z)){
            return;
        }

        BlockGrowEvent::set($world, $x, $y + $direction, $z, $vines->withProperty($property, $vines->getProperty($property) + 1));
    }

    /**
     * Now and then a mushroom spreads to a spot near it, unless there are already a few around.
     */
    private function spreadMushroom(World $world, int $x, int $y, int $z, BlockState $mushroom) : void{
        if(mt_rand(0, 24) !== 0){
            return;
        }

        $name = $mushroom->getName();
        $left = 5;

        for($dx = -4; $dx <= 4; ++$dx){
            for($dy = -1; $dy <= 1; ++$dy){
                for($dz = -4; $dz <= 4; ++$dz){
                    if(Nearby::name($world, $x + $dx, $y + $dy, $z + $dz) === $name && --$left <= 0){
                        return;
                    }
                }
            }
        }

        $step = static fn(int $fromX, int $fromY, int $fromZ) : array => [$fromX + mt_rand(-1, 1), $fromY + mt_rand(0, 1) - mt_rand(0, 1), $fromZ + mt_rand(-1, 1)];
        [$px, $py, $pz] = $step($x, $y, $z);

        for($i = 0; $i < 4; ++$i){
            if(Nearby::isAir($world, $px, $py, $pz) && $this->mushroomFits($world, $px, $py, $pz)){
                [$x, $y, $z] = [$px, $py, $pz];
            }

            [$px, $py, $pz] = $step($x, $y, $z);
        }

        if(Nearby::isAir($world, $px, $py, $pz) && $this->mushroomFits($world, $px, $py, $pz)){
            BlockGrowEvent::set($world, $px, $py, $pz, $mushroom);
        }
    }

    private function mushroomFits(World $world, int $x, int $y, int $z) : bool{
        $ground = Nearby::block($world, $x, $y - 1, $z);

        if($ground === null){
            return false;
        }

        if(in_array($ground->getName(), self::MUSHROOM_GROUND, true)){
            return true;
        }

        return Nearby::rawLight($world, $x, $y, $z) < 13 && BlockLight::filter($ground) >= 15;
    }
}