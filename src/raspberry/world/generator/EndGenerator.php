<?php

declare(strict_types=1);

namespace raspberry\world\generator;

use raspberry\block\BlockState;
use raspberry\block\BlockStateRegistry;

use raspberry\math\Vector3;

use raspberry\world\Chunk;
use raspberry\world\Dimension;

use function abs;

class EndGenerator implements Generator{

    public const int PLATFORM_X = 100;
    public const int PLATFORM_Y = 48;
    public const int PLATFORM_Z = 0;

    private const int PLATFORM_RADIUS = 2;

    private const int ISLAND_RADIUS = 48;

    private const int ISLAND_TOP = 60;

    private const int ISLAND_DEPTH = 12;

    private BlockState $endStone;

    private BlockState $obsidian;

    public function __construct(BlockStateRegistry $registry){
        $this->endStone = $registry->getDefaultState("minecraft:end_stone");
        $this->obsidian = $registry->getDefaultState("minecraft:obsidian");
    }

    public function getName() : string{
        return GeneratorFactory::THE_END;
    }

    public function getSpawn() : ?Vector3{
        return new Vector3(self::PLATFORM_X + 0.5, self::PLATFORM_Y + 1, self::PLATFORM_Z + 0.5);
    }

    public function generateChunk(Chunk $chunk) : void{
        $chunk->fillBiome(Dimension::THE_END->getDefaultBiome());
        $baseX = $chunk->getX() << 4;
        $baseZ = $chunk->getZ() << 4;

        for($x = 0; $x < Chunk::WIDTH; ++$x){
            for($z = 0; $z < Chunk::WIDTH; ++$z){
                $worldX = $baseX + $x;
                $worldZ = $baseZ + $z;
                $distance = $worldX * $worldX + $worldZ * $worldZ;

                if($distance <= self::ISLAND_RADIUS ** 2){
                    $bottom = self::ISLAND_TOP - (int) (self::ISLAND_DEPTH * (1 - $distance / self::ISLAND_RADIUS ** 2)) - 1;

                    for($y = $bottom; $y <= self::ISLAND_TOP; ++$y){
                        $chunk->setBlock($x, $y, $z, $this->endStone);
                    }
                }

                if(abs($worldX - self::PLATFORM_X) <= self::PLATFORM_RADIUS && abs($worldZ - self::PLATFORM_Z) <= self::PLATFORM_RADIUS){
                    $chunk->setBlock($x, self::PLATFORM_Y, $z, $this->obsidian);
                }
            }
        }
    }
}