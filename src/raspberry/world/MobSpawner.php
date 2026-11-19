<?php

declare(strict_types=1);

namespace raspberry\world;

use raspberry\entity\EntityFactory;
use raspberry\entity\EntityIds;
use raspberry\entity\animal\Animal;

use raspberry\math\Vector3;

use raspberry\world\biome\BiomeRegistry;
use raspberry\world\biome\BiomeTags;

use function max;
use function min;
use function mt_rand;

class MobSpawner{

    /** out of 100: how often a new chunk gets a herd, and after that another one */
    private const int HERD_CHANCE = 10;

    /** out of 100: how many of the animals are babies */
    private const int BABY_CHANCE = 5;

    /** the mob, how often it is picked next to the others, and the least and most in a herd */
    private const array HERDS = [
        [EntityIds::SHEEP, 12, 2, 3],
        [EntityIds::PIG, 10, 1, 3],
        [EntityIds::CHICKEN, 10, 2, 4],
        [EntityIds::COW, 8, 2, 3]
    ];

    private function __construct(){
    }

    public static function populate(World $world, Chunk $chunk) : void{
        while(mt_rand(0, 99) < self::HERD_CHANCE){
            [$type, , $least, $most] = self::pickHerd();
            $x = mt_rand(0, 15);
            $z = mt_rand(0, 15);

            for($left = mt_rand($least, $most); $left > 0; --$left){
                for($try = 0; $try < 4; ++$try){
                    $y = self::findGrass($chunk, $x, $z);

                    if($y !== null && BiomeRegistry::get($chunk->getBiome($x, $y, $z))->hasTag(BiomeTags::ANIMAL)){
                        $mob = EntityFactory::create($type, $world, new Vector3($chunk->getX() * 16 + $x + 0.5, $y, $chunk->getZ() * 16 + $z + 0.5), (float) mt_rand(0, 359));

                        if($mob instanceof Animal && mt_rand(0, 99) < self::BABY_CHANCE){
                            $mob->setBaby(true);
                        }
                    }

                    $x = max(0, min(15, $x + mt_rand(0, 4) - mt_rand(0, 4)));
                    $z = max(0, min(15, $z + mt_rand(0, 4) - mt_rand(0, 4)));

                    if($y !== null){
                        break;
                    }
                }
            }
        }
    }

    /**
     * @return array{string, int, int, int}
     */
    private static function pickHerd() : array{
        $total = 0;

        foreach(self::HERDS as $herd){
            $total += $herd[1];
        }

        $roll = mt_rand(0, $total - 1);

        foreach(self::HERDS as $herd){
            if(($roll -= $herd[1]) < 0){
                return $herd;
            }
        }

        return self::HERDS[0];
    }

    private static function findGrass(Chunk $chunk, int $x, int $z) : ?int{
        $top = $chunk->getHighestNonEmptySubChunk();

        if($top === null){
            return null;
        }

        for($y = min(Chunk::MAX_Y - 2, $top * 16 + 15); $y >= Chunk::MIN_Y; --$y){
            $name = $chunk->getBlock($x, $y, $z)->getName();

            if($name !== "minecraft:air"){
                return $name === "minecraft:grass_block" ? $y + 1 : null;
            }
        }

        return null;
    }
}