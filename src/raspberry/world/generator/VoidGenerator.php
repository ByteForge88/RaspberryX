<?php

declare(strict_types=1);

namespace raspberry\world\generator;

use raspberry\block\BlockState;
use raspberry\block\BlockStateRegistry;

use raspberry\math\Vector3;

use raspberry\world\Chunk;

class VoidGenerator implements Generator{

    public const PLATFORM_X = 0;
    public const PLATFORM_Y = 64;
    public const PLATFORM_Z = 0;

    private BlockState $platform;

    public function __construct(BlockStateRegistry $registry){
        $this->platform = $registry->getDefaultState("minecraft:grass_block");
    }

    public function getName() : string{
        return GeneratorFactory::VOID;
    }

    public function getSpawn() : ?Vector3{
        return new Vector3(self::PLATFORM_X + 0.5, self::PLATFORM_Y + 1, self::PLATFORM_Z + 0.5);
    }

    public function generateChunk(Chunk $chunk) : void{
        if($chunk->getX() === self::PLATFORM_X >> 4 && $chunk->getZ() === self::PLATFORM_Z >> 4){
            $chunk->setBlock(self::PLATFORM_X & 15, self::PLATFORM_Y, self::PLATFORM_Z & 15, $this->platform);
        }
    }
}