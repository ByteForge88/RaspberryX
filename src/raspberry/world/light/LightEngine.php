<?php

declare(strict_types=1);

namespace raspberry\world\light;

use raspberry\Server;

use raspberry\native\Native;

use raspberry\world\Chunk;

use FFI;

class LightEngine{

    private FFI $ffi;

    private int $handle;

    private bool $closed = false;

    public function __construct(){
        BlockLight::teach(Server::getInstance()->getBlockStateRegistry());

        $this->ffi = Native::get();
        $this->handle = $this->ffi->rx_light_new();
    }

    public function close() : void{
        if(!$this->closed){
            $this->closed = true;
            $this->ffi->rx_light_free($this->handle);
        }
    }

    /**
     * Lights a chunk that came into memory, together with the edges of its loaded neighbors.
     */
    public function addChunk(Chunk $chunk) : void{
        $this->ffi->rx_light_add_chunk($this->handle, $chunk->getX(), $chunk->getZ(), $chunk->getHandle());
    }

    public function removeChunk(int $chunkX, int $chunkZ) : void{
        $this->ffi->rx_light_remove_chunk($this->handle, $chunkX, $chunkZ);
    }

    /**
     * A block changed, the light around it follows.
     */
    public function update(int $x, int $y, int $z) : void{
        $this->ffi->rx_light_update($this->handle, $x, $y, $z);
    }

    /** the light of torches, lava, glowstone and the like, 0 to 15 */
    public function getBlockLight(int $x, int $y, int $z) : int{
        $light = $this->ffi->rx_light_get($this->handle, $x, $y, $z);
        return $light < 0 ? 0 : $light & 0x0f;
    }

    /** the light that comes from the sky, 0 to 15, whatever the time of day */
    public function getSkyLight(int $x, int $y, int $z) : int{
        $light = $this->ffi->rx_light_get($this->handle, $x, $y, $z);
        return $light < 0 ? 0 : $light >> 4;
    }

    /**
     * The lowest Y that open sky reaches in a column, null where the chunk is not loaded.
     */
    public function getSkyBottom(int $x, int $z) : ?int{
        $bottom = $this->ffi->rx_light_sky_bottom($this->handle, $x, $z);
        return $bottom === -2147483648 ? null : $bottom;
    }
}