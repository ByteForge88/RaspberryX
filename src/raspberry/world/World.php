<?php

declare(strict_types=1);

namespace raspberry\world;

use raspberry\block\BlockIds;
use raspberry\block\BlockState;
use raspberry\block\entity\BlockEntity;
use raspberry\block\entity\BlockEntityFactory;
use raspberry\block\entity\TickableBlockEntity;
use raspberry\block\entity\ContainerBlockEntity;
use raspberry\block\redstone\RedstoneUpdater;

use raspberry\world\format\io\WorldMetadata;
use raspberry\world\format\io\WorldProvider;
use raspberry\world\generator\Generator;

use raspberry\entity\Entity;
use raspberry\entity\object\ItemEntity;

use raspberry\item\Item;

use raspberry\player\Player;

use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\Packet;
use raspberry\network\mcpe\protocol\BlockActorDataPacket;
use raspberry\network\mcpe\protocol\SpawnParticleEffectPacket;

use Closure;
use InvalidArgumentException;

use function count;
use function floor;
use function mt_rand;

class World{

    private WorldMetadata $metadata;

    private Generator $generator;

    private WorldProvider $provider;

    /** @var array<string, Chunk> */
    private array $chunks = [];

    /** @var array<int, Entity> keyed by runtime ID */
    private array $entities = [];

    /** @var array<int, Player> keyed by runtime ID */
    private array $players = [];

    private bool $closed = false;

    private int $currentTick = 0;

    /** @var array<int, array{int, Closure() : void}> */
    private array $scheduledUpdates = [];

    private RedstoneUpdater $redstoneUpdater;

    public function __construct(WorldMetadata $metadata, Generator $generator, WorldProvider $provider){
        $this->metadata = $metadata;
        $this->generator = $generator;
        $this->provider = $provider;
        $this->redstoneUpdater = new RedstoneUpdater($this);
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
        $chunk = $this->loadChunk($x >> 4, $z >> 4);
        $oldState = $chunk->getBlock($x & 15, $y, $z & 15);
        $old = $oldState->getName();

        if($old !== $block->getName() && BlockEntityFactory::getSaveId($old) !== BlockEntityFactory::getSaveId($block->getName())){
            $chunk->removeBlockEntity($x & 15, $y, $z & 15);
        }

        $chunk->setBlock($x & 15, $y, $z & 15, $block);

        foreach($this->getPlayersViewing($x >> 4, $z >> 4) as $player){
            $player->getNetworkSession()->sendBlock($x, $y, $z, $block);
        }

        if(!$oldState->equals($block)){
            $this->redstoneUpdater->onBlockChanged($x, $y, $z, $oldState, $block);
        }
    }

    public function getRedstoneUpdater() : RedstoneUpdater{
        return $this->redstoneUpdater;
    }

    public function getCurrentTick() : int{
        return $this->currentTick;
    }

    public function getBlockEntity(int $x, int $y, int $z) : ?BlockEntity{
        return $this->loadChunk($x >> 4, $z >> 4)->getBlockEntity($x & 15, $y, $z & 15);
    }

    /**
     * Adds a block entity (after its block was set) and sends it to the players who see it.
     */
    public function addBlockEntity(BlockEntity $entity) : void{
        $this->loadChunk($entity->getX() >> 4, $entity->getZ() >> 4)->addBlockEntity($entity);
        $this->sendBlockEntity($entity);
    }

    /**
     * Sends a block entity's data to the players who see it, after it changed.
     */
    public function sendBlockEntity(BlockEntity $entity) : void{
        $this->getChunk($entity->getX() >> 4, $entity->getZ() >> 4)?->touch();
        $this->broadcastPacket($entity->getX(), $entity->getZ(), BlockActorDataPacket::create($entity->getX(), $entity->getY(), $entity->getZ(), $entity->getSpawnData()));
    }

    /**
     * The players who have been sent this chunk.
     *
     * @return list<Player>
     */
    public function getPlayersViewing(int $chunkX, int $chunkZ) : array{
        $viewers = [];

        foreach($this->players as $player){
            if($player->getNetworkSession()->hasChunk($chunkX, $chunkZ)){
                $viewers[] = $player;
            }
        }

        return $viewers;
    }

    /**
     * Shows a named particle effect (a ParticleEffect constant) to the players nearby.
     */
    public function addParticle(float $x, float $y, float $z, string $particle) : void{
        $this->broadcastPacket((int) floor($x), (int) floor($z), SpawnParticleEffectPacket::create($particle, $x, $y, $z));
    }

    /**
     * Sends a packet to the players who can see this block column, except $except.
     */
    public function broadcastPacket(int $x, int $z, Packet $packet, ?Player $except = null) : void{
        foreach($this->getPlayersViewing($x >> 4, $z >> 4) as $player){
            if($player !== $except){
                $player->getNetworkSession()->sendDataPacket($packet);
            }
        }
    }

    /**
     * @param Closure() : void $update
     */
    public function scheduleUpdate(int $delay, Closure $update) : void{
        $this->scheduledUpdates[] = [$this->currentTick + $delay, $update];
    }

    public function getSpawnPosition() : Vector3{
        if($this->metadata->spawn !== null){
            return $this->metadata->spawn;
        }

        $chunk = $this->loadChunk(0, 0);

        for($y = Chunk::MAX_Y; $y >= Chunk::MIN_Y; --$y){
            if($chunk->getBlock(0, $y, 0)->getName() !== BlockIds::AIR){
                return new Vector3(0.5, $y + 1, 0.5);
            }
        }

        return new Vector3(0.5, Chunk::MIN_Y, 0.5);
    }

    public function setSpawnPosition(Vector3 $position) : void{
        $this->metadata->spawn = $position;
        $this->provider->saveMetadata($this->metadata);
    }

    /**
     * @internal
     */
    public function addEntity(Entity $entity) : void{
        $this->entities[$entity->getId()] = $entity;

        if($entity instanceof Player){
            $this->players[$entity->getId()] = $entity;
        }
    }

    /**
     * @internal
     */
    public function removeEntity(Entity $entity) : void{
        unset($this->entities[$entity->getId()], $this->players[$entity->getId()]);
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

    /**
     * @return array<int, Player>
     */
    public function getPlayers() : array{
        return $this->players;
    }

    /**
     * Ticks block entities, runs scheduled updates and ticks every entity except players (their
     * session ticks them).
     */
    public function tick(int $currentTick) : void{
        $this->currentTick = $currentTick;

        foreach($this->chunks as $chunk){
            foreach($chunk->getBlockEntities() as $entity){
                if($entity instanceof TickableBlockEntity){
                    $entity->tick($this, $currentTick);
                }

                //comparators reading a container see its new contents
                if($entity instanceof ContainerBlockEntity && $entity->takeContentsChanged()){
                    $this->redstoneUpdater->update($entity->getX(), $entity->getY(), $entity->getZ());
                }
            }
        }

        $this->redstoneUpdater->tick($currentTick);

        foreach($this->scheduledUpdates as $key => [$tick, $update]){
            if($tick <= $currentTick){
                unset($this->scheduledUpdates[$key]);
                $update();
            }
        }

        foreach($this->entities as $entity){
            if(!$entity instanceof Player && !$entity->isClosed()){
                $entity->tick($currentTick);
            }
        }
    }

    /**
     * Drops an item at $position, flying off in a random direction unless $motion is given.
     */
    public function dropItem(Vector3 $position, Item $item, ?Vector3 $motion = null, int $pickupDelay = ItemEntity::DEFAULT_PICKUP_DELAY) : ?ItemEntity{
        if($item->isNull()){
            return null;
        }

        $motion ??= new Vector3(mt_rand(-100, 100) / 1000, 0.2, mt_rand(-100, 100) / 1000);

        return new ItemEntity($this, $position, $item, $motion, $pickupDelay);
    }

    public function getChunkCount() : int{
        return count($this->chunks);
    }

    private static function chunkKey(int $chunkX, int $chunkZ) : string{
        return $chunkX . ":" . $chunkZ;
    }
}