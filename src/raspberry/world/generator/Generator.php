<?php

declare(strict_types=1);

namespace raspberry\world\generator;

use raspberry\world\Chunk;

/**
 * Fills a freshly created chunk with terrain.
 *
 * A generator must be deterministic: the same chunk coordinates (and seed, for
 * generators that use one) must always produce the same blocks.
 */
interface Generator{

    /**
     * Short identifier saved with the world, e.g. "flat".
     */
    public function getName() : string;

    /**
     * Places blocks and biomes into $chunk. The chunk starts as all air
     * with the default biome; use $chunk->getX() / getZ() for its position.
     */
    public function generateChunk(Chunk $chunk) : void;

}