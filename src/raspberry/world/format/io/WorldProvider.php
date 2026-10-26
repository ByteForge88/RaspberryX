<?php

declare(strict_types=1);

namespace raspberry\world\format\io;

use raspberry\world\Chunk;

interface WorldProvider{

    public function getPath() : string;

    public function loadMetadata() : WorldMetadata;

    public function saveMetadata(WorldMetadata $metadata) : void;

    /**
     * @return Chunk|null null when the chunk was never saved and must be generated
     */
    public function loadChunk(int $chunkX, int $chunkZ) : ?Chunk;

    public function saveChunk(Chunk $chunk) : void;

    public function close() : void;

}