<?php

declare(strict_types=1);

namespace raspberry\event\world;

use raspberry\world\Chunk;
use raspberry\world\World;

/**
 * A chunk is about to be saved and dropped from memory.
 */
class ChunkUnloadEvent extends WorldEvent{

    private Chunk $chunk;

    public function __construct(World $world, Chunk $chunk){
        $this->world = $world;
        $this->chunk = $chunk;
    }

    public function getChunk() : Chunk{
        return $this->chunk;
    }
}