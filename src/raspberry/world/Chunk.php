<?php

declare(strict_types=1);

namespace raspberry\world;

use raspberry\block\BlockIds;
use raspberry\block\BlockState;
use raspberry\block\BlockStateIds;
use raspberry\block\entity\BlockEntity;

use raspberry\nbt\NbtSerializer;

use raspberry\native\Native;

use raspberry\world\biome\BiomeIds;

use Closure;
use FFI;
use InvalidArgumentException;
use LogicException;
use UnexpectedValueException;

use function strlen;

class Chunk{

    public const WIDTH = 16;

    public const MIN_Y = -64;
    public const MAX_Y = 319;

    public const MIN_SUBCHUNK = self::MIN_Y >> 4; //-4
    public const MAX_SUBCHUNK = self::MAX_Y >> 4; //19

    public const MAX_LAYERS = 2;

    public const DEFAULT_BIOME = BiomeIds::PLAINS;

    /** what the native side answers when the chunk is all air */
    private const NO_SUBCHUNK = -128;

    private FFI $ffi;

    private int $handle;

    private int $x;
    private int $z;

    /** @var array<int, BlockEntity> */
    private array $blockEntities = [];

    private bool $changed = false;

    /** goes up with every change a player could see, so a chunk being sent knows it is stale */
    private int $revision = 0;

    public function __construct(int $x, int $z, int $biomeId = self::DEFAULT_BIOME){
        self::checkBiome($biomeId);

        $this->ffi = Native::get();
        $this->x = $x;
        $this->z = $z;
        $this->handle = $this->ffi->rx_chunk_new(BlockStateIds::get(self::air()), $biomeId);
    }

    public function __destruct(){
        $this->ffi->rx_chunk_free($this->handle);
    }

    private function __clone(){
        //two objects would free the same native chunk
    }

    public function getX() : int{
        return $this->x;
    }

    public function getZ() : int{
        return $this->z;
    }

    public function getRevision() : int{
        return $this->revision;
    }

    /**
     * Something players see in the chunk changed outside its blocks, like a block entity's data.
     */
    public function touch() : void{
        ++$this->revision;
    }

    public function getBlock(int $x, int $y, int $z, int $layer = 0) : BlockState{
        self::check($x, $y, $z, $layer);

        return BlockStateIds::fromId($this->ffi->rx_chunk_get_block($this->handle, $x, $y, $z, $layer));
    }

    public function setBlock(int $x, int $y, int $z, BlockState $block, int $layer = 0) : void{
        self::check($x, $y, $z, $layer);

        $this->ffi->rx_chunk_set_block($this->handle, $x, $y, $z, $layer, BlockStateIds::get($block));
        $this->changed = true;
        ++$this->revision;
    }

    /**
     * Sets every block of the chunk from $minY to $maxY, much quicker than one by one.
     */
    public function fill(int $minY, int $maxY, BlockState $block, int $layer = 0) : void{
        self::check(0, $minY, 0, $layer);
        self::check(0, $maxY, 0, $layer);

        $this->ffi->rx_chunk_fill($this->handle, $minY, $maxY, $layer, BlockStateIds::get($block));
        $this->changed = true;
        ++$this->revision;
    }

    /**
     * The block entity at local X/Z and world Y, if any.
     */
    public function getBlockEntity(int $x, int $y, int $z) : ?BlockEntity{
        return $this->blockEntities[self::blockIndex($x, $y, $z)] ?? null;
    }

    /**
     * Adds a block entity at its own position, which must be in this chunk.
     */
    public function addBlockEntity(BlockEntity $entity) : void{
        if($entity->getX() >> 4 !== $this->x || $entity->getZ() >> 4 !== $this->z){
            throw new InvalidArgumentException("Block entity at {$entity->getX()}, {$entity->getZ()} is not in chunk {$this->x}, {$this->z}");
        }

        $this->blockEntities[self::blockIndex($entity->getX() & 15, $entity->getY(), $entity->getZ() & 15)] = $entity;
        $this->changed = true;
        ++$this->revision;
    }

    public function removeBlockEntity(int $x, int $y, int $z) : void{
        $index = self::blockIndex($x, $y, $z);

        if(isset($this->blockEntities[$index])){
            unset($this->blockEntities[$index]);
            $this->changed = true;
            ++$this->revision;
        }
    }

    /**
     * @return array<int, BlockEntity>
     */
    public function getBlockEntities() : array{
        return $this->blockEntities;
    }

    private static function blockIndex(int $x, int $y, int $z) : int{
        return (($y - self::MIN_Y) << 8) | ($z << 4) | $x;
    }

    public function getBiome(int $x, int $y, int $z) : int{
        self::check($x, $y, $z, 0);

        return $this->ffi->rx_chunk_get_biome($this->handle, $x, $y, $z);
    }

    public function setBiome(int $x, int $y, int $z, int $biomeId) : void{
        self::check($x, $y, $z, 0);
        self::checkBiome($biomeId);

        $this->ffi->rx_chunk_set_biome($this->handle, $x, $y, $z, $biomeId);
        $this->changed = true;
        ++$this->revision;
    }

    public function fillBiome(int $biomeId) : void{
        self::checkBiome($biomeId);

        $this->ffi->rx_chunk_fill_biome($this->handle, $biomeId);
        $this->changed = true;
        ++$this->revision;
    }

    /**
     * Highest sub-chunk Y that contains blocks, or null when the whole chunk is air.
     */
    public function getHighestNonEmptySubChunk() : ?int{
        $y = $this->ffi->rx_chunk_highest_subchunk($this->handle);

        return $y === self::NO_SUBCHUNK ? null : $y;
    }

    public function isEmpty() : bool{
        return $this->getHighestNonEmptySubChunk() === null;
    }

    /**
     * Whether block entities, blocks or biomes changed since the last save
     */
    public function hasChanged() : bool{
        if($this->changed){
            return true;
        }

        foreach($this->blockEntities as $entity){
            if($entity->isChanged()){
                return true;
            }
        }

        return false;
    }

    public function setChanged(bool $changed = true) : void{
        $this->changed = $changed;

        if(!$changed){
            foreach($this->blockEntities as $entity){
                $entity->setChanged(false);
            }
        }
    }

    /**
     * Drops palette entries no block uses any more.
     */
    public function collectGarbage() : void{
        $this->ffi->rx_chunk_collect_garbage($this->handle);
    }

    /**
     * The blocks and biomes as the native side writes them, for saving. Per sub-chunk from the
     * bottom: a layer count byte, each layer, then the biomes, each of those a u32 palette
     * count, the palette (u32 IDs) and, when there is more than one entry, 4096 u16 offsets.
     */
    public function exportStorage() : string{
        $out = $this->ffi->new("char*");
        $length = $this->ffi->new("size_t");

        if($this->ffi->rx_chunk_export($this->handle, FFI::addr($out), FFI::addr($length)) !== 1){
            throw new LogicException("The native chunk is gone");
        }

        return Native::take($out, $length->cdata);
    }

    /**
     * Replaces the blocks and biomes with what exportStorage() gave. Does not mark the chunk as
     * changed.
     */
    public function importStorage(string $data) : void{
        if($this->ffi->rx_chunk_import($this->handle, $data, strlen($data)) !== 1){
            throw new UnexpectedValueException("The block data is damaged");
        }

        ++$this->revision;
    }

    /**
     * Encodes the chunk for the client on a worker thread: a LevelChunk packet in a batch of
     * its own, compressed from $threshold bytes (negative for not at all). $onDone gets the
     * bytes for the transport, on the main thread.
     *
     * @param Closure(string) : void $onDone
     */
    public function encode(int $threshold, Closure $onDone) : void{
        $nbt = NbtSerializer::network();
        $extra = "";

        foreach($this->blockEntities as $entity){
            $extra .= $nbt->write($entity->getSpawnData());
        }

        $id = $this->ffi->rx_chunk_encode($this->handle, $this->x, $this->z, $this->revision, $extra, strlen($extra), $threshold);

        if($id === 0){
            throw new LogicException("The native chunk is gone");
        }

        Native::await($id, $onDone);
    }

    private static function check(int $x, int $y, int $z, int $layer) : void{
        if($x < 0 || $x >= self::WIDTH || $z < 0 || $z >= self::WIDTH){
            throw new InvalidArgumentException("Local X and Z must be between 0 and 15, got {$x}, {$z}");
        }

        if($y < self::MIN_Y || $y > self::MAX_Y){
            throw new InvalidArgumentException("Y must be between " . self::MIN_Y . " and " . self::MAX_Y . ", got {$y}");
        }

        if($layer < 0 || $layer >= self::MAX_LAYERS){
            throw new InvalidArgumentException("Block layer must be between 0 and " . (self::MAX_LAYERS - 1) . ", got {$layer}");
        }
    }

    private static function checkBiome(int $biomeId) : void{
        if($biomeId < 0 || $biomeId > 0xffff){
            throw new InvalidArgumentException("Biome ID must be between 0 and 65535, got {$biomeId}");
        }
    }

    private static function air() : BlockState{
        static $air = null;
        return $air ??= new BlockState(BlockIds::AIR);
    }
}