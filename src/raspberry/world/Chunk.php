<?php

declare(strict_types=1);

namespace raspberry\world;

use InvalidArgumentException;
use raspberry\block\BlockState;
use raspberry\world\biome\BiomeIds;
use raspberry\world\format\SubChunk;

/**
 * A 16 x 384 x 16 column of the world, split into 24 sub-chunks (Y -64 to 319).
 */
class Chunk{

    public const WIDTH = 16;

    public const MIN_Y = -64;
    public const MAX_Y = 319;

    public const MIN_SUBCHUNK = self::MIN_Y >> 4; //-4
    public const MAX_SUBCHUNK = self::MAX_Y >> 4; //19

    public const DEFAULT_BIOME = BiomeIds::PLAINS;

    private int $x;
    private int $z;

    /** @var array<int, SubChunk> keyed by sub-chunk Y (-4 to 19) */
    private array $subChunks = [];

    private bool $changed = false;

    public function __construct(int $x, int $z, int $biomeId = self::DEFAULT_BIOME){
        $this->x = $x;
        $this->z = $z;

        for($y = self::MIN_SUBCHUNK; $y <= self::MAX_SUBCHUNK; ++$y){
            $this->subChunks[$y] = new SubChunk($biomeId);
        }
    }

    public function getX() : int{
        return $this->x;
    }

    public function getZ() : int{
        return $this->z;
    }

    public function getBlock(int $x, int $y, int $z, int $layer = 0) : BlockState{
        return $this->getSubChunkAt($x, $y, $z)->getBlock($x, $y & 0x0f, $z, $layer);
    }

    public function setBlock(int $x, int $y, int $z, BlockState $block, int $layer = 0) : void{
        $this->getSubChunkAt($x, $y, $z)->setBlock($x, $y & 0x0f, $z, $block, $layer);

        $this->changed = true;
    }

    public function getBiome(int $x, int $y, int $z) : int{
        return $this->getSubChunkAt($x, $y, $z)->getBiome($x, $y & 0x0f, $z);
    }

    public function setBiome(int $x, int $y, int $z, int $biomeId) : void{
        $this->getSubChunkAt($x, $y, $z)->setBiome($x, $y & 0x0f, $z, $biomeId);

        $this->changed = true;
    }

    /**
     * Replaces a whole sub-chunk, e.g. when loading from disk. Does not mark the chunk as changed.
     */
    public function setSubChunk(int $subChunkY, SubChunk $subChunk) : void{
        $this->getSubChunk($subChunkY);
        $this->subChunks[$subChunkY] = $subChunk;
    }

    public function getSubChunk(int $subChunkY) : SubChunk{
        return $this->subChunks[$subChunkY] ?? throw new InvalidArgumentException("Sub-chunk Y must be between " . self::MIN_SUBCHUNK . " and " . self::MAX_SUBCHUNK . ", got {$subChunkY}");
    }

    /**
     * @return array<int, SubChunk> keyed by sub-chunk Y, bottom to top
     */
    public function getSubChunks() : array{
        return $this->subChunks;
    }

    /**
     * Highest sub-chunk Y that contains blocks, or null when the whole chunk is air.
     * The network serializer uses this to skip empty sub-chunks at the top.
     */
    public function getHighestNonEmptySubChunk() : ?int{
        for($y = self::MAX_SUBCHUNK; $y >= self::MIN_SUBCHUNK; --$y){
            if(!$this->subChunks[$y]->isEmpty()){
                return $y;
            }
        }

        return null;
    }

    public function isEmpty() : bool{
        return $this->getHighestNonEmptySubChunk() === null;
    }

    /**
     * Whether blocks or biomes changed since the last save (used in step 6).
     */
    public function hasChanged() : bool{
        return $this->changed;
    }

    public function setChanged(bool $changed = true) : void{
        $this->changed = $changed;
    }

    public function collectGarbage() : void{
        foreach($this->subChunks as $subChunk){
            $subChunk->collectGarbage();
        }
    }

    private function getSubChunkAt(int $x, int $y, int $z) : SubChunk{
        if($x < 0 || $x >= self::WIDTH || $z < 0 || $z >= self::WIDTH){
            throw new InvalidArgumentException("Local X and Z must be between 0 and 15, got {$x}, {$z}");
        }

        if($y < self::MIN_Y || $y > self::MAX_Y){
            throw new InvalidArgumentException("Y must be between " . self::MIN_Y . " and " . self::MAX_Y . ", got {$y}");
        }

        return $this->subChunks[$y >> 4];
    }
}