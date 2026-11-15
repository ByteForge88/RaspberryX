<?php

declare(strict_types=1);

namespace raspberry\event\world;

use raspberry\world\Chunk;
use raspberry\world\World;

/**
 * A chunk came into memory, read from disk or just generated.
 */
class ChunkLoadEvent extends WorldEvent{

    private Chunk $chunk;

    private bool $generated;

    public function __construct(World $world, Chunk $chunk, bool $generated){
        $this->world = $world;
        $this->chunk = $chunk;
        $this->generated = $generated;
    }

    public function getChunk() : Chunk{
        return $this->chunk;
    }

    /**
     * Whether the chunk is new, made by the generator just now.
     */
    public function isNewChunk() : bool{
        return $this->generated;
    }
}