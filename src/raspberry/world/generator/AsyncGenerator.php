<?php

declare(strict_types=1);

namespace raspberry\world\generator;

use raspberry\world\Chunk;

use Closure;

/**
 * A generator that makes chunks on worker threads. The world asks it for chunks players are
 * about to see and carries on while they are made.
 */
interface AsyncGenerator extends Generator{

    /**
     * Starts filling $chunk. $onDone runs on the main thread once the chunk holds its blocks.
     *
     * @param Closure() : void $onDone
     */
    public function generateChunkLater(Chunk $chunk, Closure $onDone) : void;

}