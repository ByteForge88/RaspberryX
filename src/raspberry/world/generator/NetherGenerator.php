<?php

declare(strict_types=1);

namespace raspberry\world\generator;

use raspberry\block\BlockState;
use raspberry\block\BlockStateRegistry;

use raspberry\math\Vector3;

use raspberry\world\Chunk;
use raspberry\world\Dimension;

class NetherGenerator implements Generator{

    private const int FLOOR_TOP = 31;

    private const int CEILING_BOTTOM = 123;

    private BlockState $bedrock;

    private BlockState $netherrack;

    public function __construct(BlockStateRegistry $registry){
        $this->bedrock = $registry->getDefaultState("minecraft:bedrock");
        $this->netherrack = $registry->getDefaultState("minecraft:netherrack");
    }

    public function getName() : string{
        return GeneratorFactory::NETHER;
    }

    public function getSpawn() : ?Vector3{
        return new Vector3(0.5, self::FLOOR_TOP + 1, 0.5);
    }

    public function generateChunk(Chunk $chunk) : void{
        $dimension = Dimension::NETHER;
        $chunk->fillBiome($dimension->getDefaultBiome());
        $chunk->fill($dimension->getMinY(), $dimension->getMinY(), $this->bedrock);
        $chunk->fill($dimension->getMinY() + 1, self::FLOOR_TOP, $this->netherrack);
        $chunk->fill(self::CEILING_BOTTOM, $dimension->getMaxY() - 1, $this->netherrack);
        $chunk->fill($dimension->getMaxY(), $dimension->getMaxY(), $this->bedrock);
    }
}