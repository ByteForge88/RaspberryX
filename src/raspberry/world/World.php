<?php

declare(strict_types=1);

namespace raspberry\world;

use raspberry\block\BlockIds;
use raspberry\block\BlockState;

use raspberry\world\format\io\WorldMetadata;
use raspberry\world\format\io\WorldProvider;
use raspberry\world\generator\Generator;

use raspberry\entity\Entity;

use raspberry\math\Vector3;

use InvalidArgumentException;

use function count;

class World{

    private WorldMetadata $metadata;

    private Generator $generator;

    private WorldProvider $provider;

    /** @var array<string, Chunk> */
    private array $chunks = [];

    /** @var array<int, Entity> keyed by runtime ID */
    private array $entities = [];

    private bool $closed = false;

    public function __construct(WorldMetadata $metadata, Generator $generator, WorldProvider $provider){
        $this->metadata = $metadata;
        $this->generator = $generator;
        $this->provider = $provider;
    }

    public function getName() : string{
        return $this->metadata->name;
    }

    public function getMetadata() : WorldMetadata{
        return $this->metadata;
    }

    public function getGenerator() : Generator{
        return $this->generator;
    }

    public function getProvider() : WorldProvider{
        return $this->provider;
    }

    /**
     * The chunk if it is already in memory, otherwise null. Never loads or generates.
     */
    public function getChunk(int $chunkX, int $chunkZ) : ?Chunk{
        return $this->chunks[self::chunkKey($chunkX, $chunkZ)] ?? null;
    }

    public function isChunkLoaded(int $chunkX, int $chunkZ) : bool{
        return isset($this->chunks[self::chunkKey($chunkX, $chunkZ)]);
    }

    /**
     * Returns the chunk from memory, else from disk, else generates it.
     *
     * @param bool|null $generated set to true when the chunk had to be generated
     */
    public function loadChunk(int $chunkX, int $chunkZ, ?bool &$generated = null) : Chunk{
        $key = self::chunkKey($chunkX, $chunkZ);
        $generated = false;

        if(isset($this->chunks[$key])){
            return $this->chunks[$key];
        }

        $chunk = $this->provider->loadChunk($chunkX, $chunkZ);

        if($chunk === null){
            $chunk = new Chunk($chunkX, $chunkZ);

            $this->generator->generateChunk($chunk);

            $chunk->collectGarbage();
            $chunk->setChanged();//new chunks have never been saved
            $generated = true;
        }

        return $this->chunks[$key] = $chunk;
    }

    /**
     * Loads every chunk within $radius chunks of the given chunk (a square).
     *
     * @return array{loaded: int, generated: int} chunks read from disk / newly generated
     */
    public function loadChunksAround(int $centerX, int $centerZ, int $radius) : array{
        if($radius < 0){
            throw new InvalidArgumentException("Radius cannot be negative");
        }

        $result = ["loaded" => 0, "generated" => 0];

        for($x = $centerX - $radius; $x <= $centerX + $radius; ++$x){
            for($z = $centerZ - $radius; $z <= $centerZ + $radius; ++$z){
                if(!$this->isChunkLoaded($x, $z)){
                    $this->loadChunk($x, $z, $generated);
                    ++$result[$generated ? "generated" : "loaded"];
                }
            }
        }

        return $result;
    }

    /**
     * Saves the chunk if it changed, then drops it from memory.
     */
    public function unloadChunk(int $chunkX, int $chunkZ) : void{
        $key = self::chunkKey($chunkX, $chunkZ);
        $chunk = $this->chunks[$key] ?? null;

        if($chunk === null){
            return;
        }

        if($chunk->hasChanged()){
            $this->provider->saveChunk($chunk);
        }

        unset($this->chunks[$key]);
    }

    /**
     * Writes every changed chunk to disk.
     *
     * @return int number of chunks written
     */
    public function save() : int{
        $saved = 0;

        foreach($this->chunks as $chunk){
            if($chunk->hasChanged()){
                $this->provider->saveChunk($chunk);
                $chunk->setChanged(false);
                ++$saved;
            }
        }

        return $saved;
    }

    /**
     * Saves everything and releases the provider. The world must not be used afterwards.
     *
     * @return int number of chunks written
     */
    public function close() : int{
        if($this->closed){
            return 0;
        }

        $saved = $this->save();

        $this->provider->close();
        $this->chunks = [];
        $this->closed = true;

        return $saved;
    }

    public function getBlock(int $x, int $y, int $z) : BlockState{
        return $this->loadChunk($x >> 4, $z >> 4)->getBlock($x & 15, $y, $z & 15);
    }

    public function setBlock(int $x, int $y, int $z, BlockState $block) : void{
        $this->loadChunk($x >> 4, $z >> 4)->setBlock($x & 15, $y, $z & 15, $block);
    }

    /**
     * Where new players appear: above the highest block at 0, 0 (centre of the block).
     * TODO: store a custom spawn in the world metadata
     */
    public function getSpawnPosition() : Vector3{
        $chunk = $this->loadChunk(0, 0);

        for($y = Chunk::MAX_Y; $y >= Chunk::MIN_Y; --$y){
            if($chunk->getBlock(0, $y, 0)->getName() !== BlockIds::AIR){
                return new Vector3(0.5, $y + 1, 0.5);
            }
        }

        return new Vector3(0.5, Chunk::MIN_Y, 0.5);
    }

    /**
     * @internal called by Entity
     */
    public function addEntity(Entity $entity) : void{
        $this->entities[$entity->getId()] = $entity;
    }

    /**
     * @internal called by Entity::close()
     */
    public function removeEntity(Entity $entity) : void{
        unset($this->entities[$entity->getId()]);
    }

    public function getEntity(int $runtimeId) : ?Entity{
        return $this->entities[$runtimeId] ?? null;
    }

    /**
     * @return array<int, Entity>
     */
    public function getEntities() : array{
        return $this->entities;
    }

    public function getChunkCount() : int{
        return count($this->chunks);
    }

    private static function chunkKey(int $chunkX, int $chunkZ) : string{
        return $chunkX . ":" . $chunkZ;
    }
}