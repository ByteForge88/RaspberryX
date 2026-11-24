<?php

declare(strict_types=1);

namespace raspberry\world;

use raspberry\Server;

use raspberry\block\BlockIds;
use raspberry\block\BlockState;
use raspberry\block\BlockSupport;
use raspberry\block\entity\BlockEntity;
use raspberry\block\entity\BlockEntityFactory;
use raspberry\block\entity\TickableBlockEntity;
use raspberry\block\entity\ContainerBlockEntity;
use raspberry\block\redstone\RedstoneUpdater;

use raspberry\world\biome\Biome;
use raspberry\world\biome\BiomeRegistry;

use raspberry\world\format\io\WorldMetadata;
use raspberry\world\format\io\WorldProvider;
use raspberry\world\generator\AsyncGenerator;
use raspberry\world\generator\Generator;
use raspberry\world\light\LightEngine;
use raspberry\world\liquid\LiquidEngine;

use raspberry\entity\Entity;
use raspberry\entity\EntityFactory;
use raspberry\entity\ai\MobEngine;
use raspberry\entity\object\ItemEntity;

use raspberry\event\entity\EntitySpawnEvent;
use raspberry\event\entity\ItemSpawnEvent;
use raspberry\event\world\ChunkLoadEvent;
use raspberry\event\world\ChunkUnloadEvent;
use raspberry\event\world\SpawnChangeEvent;
use raspberry\event\world\WorldSaveEvent;

use raspberry\item\Item;

use raspberry\player\Player;

use raspberry\math\Vector3;

use raspberry\native\Native;

use raspberry\nbt\tag\CompoundTag;

use raspberry\network\mcpe\protocol\Packet;
use raspberry\network\mcpe\protocol\SetTimePacket;
use raspberry\network\mcpe\protocol\BlockActorDataPacket;
use raspberry\network\mcpe\protocol\GameRulesChangedPacket;
use raspberry\network\mcpe\protocol\SpawnParticleEffectPacket;

use Closure;
use InvalidArgumentException;

use function cos;
use function count;
use function floor;
use function fmod;
use function intdiv;
use function max;
use function min;
use function mt_rand;
use function usleep;

use const M_PI;

class World{

    /** ticks in a day */
    public const int DAY_LENGTH = 24000;

    /** when the clock passes these, it is that time of day */
    public const int TIME_DAY = 1000;
    public const int TIME_NOON = 6000;
    public const int TIME_SUNSET = 12000;
    public const int TIME_NIGHT = 13000;
    public const int TIME_MIDNIGHT = 18000;
    public const int TIME_SUNRISE = 23000;

    /** the client moves its own clock on, this keeps the two from drifting apart */
    private const int TIME_SYNC_TICKS = 200;

    /** a chunk nothing has needed for this many ticks is saved and unloaded */
    public const int UNLOAD_DELAY = 600;

    private const int UNLOAD_CHECK_TICKS = 20;

    /** saving is slow, so only this many chunks go per check */
    private const int UNLOADS_PER_CHECK = 16;

    /** an update waiting in a chunk that does not tick looks again this much later */
    private const int FROZEN_DELAY = 20;

    private WorldMetadata $metadata;

    private Generator $generator;

    private WorldProvider $provider;

    /** @var array<string, Chunk> */
    private array $chunks = [];

    /** @var array<string, list<Closure(Chunk) : void>> */
    private array $generating = [];

    /** @var array<int, Entity> keyed by runtime ID */
    private array $entities = [];

    /** @var array<int, Player> keyed by runtime ID */
    private array $players = [];

    private bool $closed = false;

    private int $currentTick = 0;

    /** @var array<int, array{int, Closure() : void, int|null, int|null}> */
    private array $scheduledUpdates = [];

    private TickingAreas $tickingAreas;

    /** @var array<string, Chunk> */
    private array $tickingChunks = [];

    private string $tickingSignature = "";

    /** @var array<string, int> */
    private array $chunkLastUsed = [];

    private RedstoneUpdater $redstoneUpdater;

    private MobEngine $mobEngine;

    private LightEngine $light;

    private RandomTicker $randomTicker;

    private LiquidEngine $liquids;

    private Weather $weather;

    private BlockTicker $blockTicker;

    private Sleeping $sleeping;

    /** @var array<int, Entity> */
    private array $spawning = [];

    private GameRules $gameRules;

    public function __construct(WorldMetadata $metadata, Generator $generator, WorldProvider $provider){
        $this->metadata = $metadata;
        $this->generator = $generator;
        $this->provider = $provider;
        $this->redstoneUpdater = new RedstoneUpdater($this);
        $this->mobEngine = new MobEngine($this);
        $this->light = new LightEngine();
        $this->randomTicker = new RandomTicker($this);
        $this->liquids = new LiquidEngine($this);
        $this->weather = new Weather($this, $metadata);
        $this->blockTicker = new BlockTicker($this);
        $this->sleeping = new Sleeping($this);
        $this->gameRules = new GameRules($metadata->gameRules);
        $this->tickingAreas = new TickingAreas($metadata);
    }

    public function getTickingAreas() : TickingAreas{
        return $this->tickingAreas;
    }

    public function preloadTickingAreas() : void{
        foreach($this->tickingAreas->getAll() as $area){
            if($area->isPreload()){
                foreach($area->getChunks() as [$chunkX, $chunkZ]){
                    $this->loadChunk($chunkX, $chunkZ);
                }
            }
        }
    }

    /**
     * Whether blocks, liquids, block entities and entities in the chunk run this tick: it is
     * within tick-distance of a player, or in a ticking area while a player is in the world.
     */
    public function isChunkTicking(int $chunkX, int $chunkZ) : bool{
        return isset($this->tickingChunks[self::chunkKey($chunkX, $chunkZ)]);
    }

    /**
     * @return array<string, Chunk>
     */
    public function getTickingChunks() : array{
        return $this->tickingChunks;
    }

    private function updateTickingChunks() : void{
        $distance = Server::getInstance()->getTickDistance();
        $signature = $distance . "/" . $this->tickingAreas->getRevision() . "/" . count($this->chunks);
        $centers = [];

        foreach($this->players as $player){
            $position = $player->getPosition();
            $centers[] = [$position->getChunkX(), $position->getChunkZ()];
            $signature .= "/" . $position->getChunkX() . ":" . $position->getChunkZ();
        }

        if($signature === $this->tickingSignature){
            return;
        }

        $this->tickingSignature = $signature;
        $ticking = [];

        foreach($centers as [$centerX, $centerZ]){
            for($dx = -$distance; $dx <= $distance; ++$dx){
                for($dz = -$distance; $dz <= $distance; ++$dz){
                    $key = self::chunkKey($centerX + $dx, $centerZ + $dz);

                    if($dx * $dx + $dz * $dz <= $distance * $distance && isset($this->chunks[$key])){
                        $ticking[$key] = $this->chunks[$key];
                    }
                }
            }
        }

        //like vanilla, ticking areas only run while someone is in the world
        if($centers !== []){
            foreach($this->tickingAreas->getAll() as $area){
                foreach($area->getChunks() as [$chunkX, $chunkZ]){
                    $key = self::chunkKey($chunkX, $chunkZ);

                    if(isset($this->chunks[$key])){
                        $ticking[$key] = $this->chunks[$key];
                    }elseif(!isset($this->generating[$key])){
                        $this->requestChunk($chunkX, $chunkZ, static function(Chunk $chunk) : void{});
                    }
                }
            }
        }

        $this->tickingChunks = $ticking;

        $coords = [];

        foreach($ticking as $chunk){
            $coords[] = [$chunk->getX(), $chunk->getZ()];
        }

        $this->liquids->setTickingChunks($coords);
        $this->mobEngine->setTickingChunks($coords);
    }

    /**
     * Saves and unloads the chunks no player can see and no ticking area holds, once they have
     * not been needed for UNLOAD_DELAY ticks.
     */
    private function unloadUnusedChunks() : void{
        $held = $this->tickingAreas->getChunkKeys();
        $wanted = [];

        foreach($this->players as $player){
            $wanted[] = $player->getNetworkSession()->getWantedChunks();
        }

        $unloaded = 0;

        foreach($this->chunks as $key => $chunk){
            $used = isset($this->tickingChunks[$key]) || isset($held[$key]) || isset($this->generating[$key]);

            foreach($wanted as $chunks){
                if($used){
                    break;
                }

                $used = isset($chunks[$key]);
            }

            if($used){
                $this->chunkLastUsed[$key] = $this->currentTick;
                continue;
            }

            if($this->currentTick - ($this->chunkLastUsed[$key] ??= $this->currentTick) < self::UNLOAD_DELAY){
                continue;
            }

            $this->unloadChunk($chunk->getX(), $chunk->getZ());

            if(++$unloaded >= self::UNLOADS_PER_CHECK){
                break;
            }
        }
    }

    public function getGameRules() : GameRules{
        return $this->gameRules;
    }

    public function getSleeping() : Sleeping{
        return $this->sleeping;
    }

    /**
     * Changes a game rule and tells the players. False when there is no such rule or the value
     * is not of its type.
     */
    public function setGameRule(string $name, bool|int $value) : bool{
        if(!$this->gameRules->set($name, $value)){
            return false;
        }

        $this->metadata->gameRules = $this->gameRules->toArray();
        $this->broadcastToPlayers(GameRulesChangedPacket::create([$name => $value]));

        //a stopped clock is stopped where the server has it
        if($name === GameRules::DO_DAYLIGHT_CYCLE){
            $this->broadcastToPlayers(SetTimePacket::create($this->metadata->time));
        }

        return true;
    }

    /**
     * The world clock in ticks. The time of day is this modulo DAY_LENGTH.
     */
    public function getTime() : int{
        return $this->metadata->time;
    }

    public function setTime(int $time) : void{
        $this->metadata->time = max(0, $time);
        $this->broadcastToPlayers(SetTimePacket::create($this->metadata->time));
    }

    /** 0 to 23999: 0 is sunrise's end, 6000 noon, 18000 midnight */
    public function getTimeOfDay() : int{
        return $this->metadata->time % self::DAY_LENGTH;
    }

    /** how many days have gone by */
    public function getDay() : int{
        return intdiv($this->metadata->time, self::DAY_LENGTH);
    }

    /** ticks the world has run, which /time does not change */
    public function getGameTime() : int{
        return $this->metadata->gameTime;
    }

    /** 0 is a full moon, 4 a new moon */
    public function getMoonPhase() : int{
        return $this->getDay() % 8;
    }

    /**
     * Where the sun is, from 0 to 1: 0 at noon, 0.5 at midnight. It moves a little faster
     * around sunrise and sunset, the way the client draws it.
     */
    public function getCelestialAngle() : float{
        $part = fmod($this->getTimeOfDay() / self::DAY_LENGTH - 0.25, 1.0);

        if($part < 0){
            ++$part;
        }

        return ($part * 2.0 + (0.5 - cos($part * M_PI) / 2.0)) / 3.0;
    }

    public function getSunAngle() : float{
        return $this->getCelestialAngle() * 2.0 * M_PI;
    }

    /**
     * How much darker than full daylight the sky is, 0 at day to 11 at night. Rain and thunder
     * darken it too.
     */
    public function getSkyDarken() : int{
        $brightness = 0.5 + 2.0 * max(-0.25, min(0.25, cos($this->getSunAngle())));
        $brightness *= 1.0 - $this->weather->getRainLevel() * 5.0 / 16.0;
        $brightness *= 1.0 - $this->weather->getThunderLevel() * 5.0 / 16.0;
        return (int) ((1.0 - $brightness) * 11.0);
    }

    public function getBlockTicker() : BlockTicker{
        return $this->blockTicker;
    }

    public function getWeather() : Weather{
        return $this->weather;
    }

    /** whether rain falls on the block, see Weather::isRainingAt() */
    public function isRainingAt(int $x, int $y, int $z) : bool{
        return $this->weather->isRainingAt($x, $y, $z);
    }

    /** true from sunrise to sunset, when the sky is bright enough to burn the undead */
    public function isDaytime() : bool{
        return $this->getSkyDarken() < 4;
    }

    private function broadcastToPlayers(Packet $packet) : void{
        foreach($this->players as $player){
            $player->getNetworkSession()->sendDataPacket($packet);
        }
    }

    public function getMobEngine() : MobEngine{
        return $this->mobEngine;
    }

    public function getLightEngine() : LightEngine{
        return $this->light;
    }

    /** the light of torches, lava, glowstone and the like at a block, 0 to 15 */
    public function getBlockLight(int $x, int $y, int $z) : int{
        return $this->light->getBlockLight($x, $y, $z);
    }

    /** the light that reaches a block from the sky, 0 to 15, as it would be at noon */
    public function getSkyLight(int $x, int $y, int $z) : int{
        return $this->light->getSkyLight($x, $y, $z);
    }

    /**
     * The light at a block the way the game counts it for growing and spawning: the brighter of
     * its block light and its sky light, the sky's dimmed by the time of day.
     */
    public function getLight(int $x, int $y, int $z) : int{
        return max($this->light->getBlockLight($x, $y, $z), $this->light->getSkyLight($x, $y, $z) - $this->getSkyDarken());
    }

    /** whether nothing is above the block that stops the light of the sky */
    public function canSeeSky(int $x, int $y, int $z) : bool{
        $bottom = $this->light->getSkyBottom($x, $z);
        return $bottom !== null && $y >= $bottom;
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

        if(isset($this->generating[$key])){
            $this->waitForGeneration([$key]);
            $generated = true;

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

        if(!isset($this->chunks[$key])){
            $this->chunks[$key] = $chunk;
            $this->onChunkLoaded($chunk, $generated);
        }

        return $this->chunks[$key];
    }

    /**
     * A chunk came into memory: the mobs hear of it, the ones saved in it come back, and a
     * new chunk gets its animals.
     */
    private function onChunkLoaded(Chunk $chunk, bool $generated) : void{
        $this->chunkLastUsed[self::chunkKey($chunk->getX(), $chunk->getZ())] = $this->currentTick;
        $this->light->addChunk($chunk);
        $this->liquids->addChunk($chunk);
        $this->mobEngine->setChunk($chunk->getX(), $chunk->getZ(), $chunk);
        $this->redstoneUpdater->findDaylightDetectors($chunk);
        $this->redstoneUpdater->getLightningRods()->findIn($chunk);

        foreach($chunk->getSavedEntities() as $tag){
            EntityFactory::load($this, $tag);
        }

        if($generated){
            MobSpawner::populate($this, $chunk);
        }

        (new ChunkLoadEvent($this, $chunk, $generated))->call();
    }

    /**
     * @return array<string, list<CompoundTag>>
     */
    private function saveEntities() : array{
        $saved = [];

        foreach($this->entities as $entity){
            $tag = $entity->isClosed() ? null : $entity->save();

            if($tag !== null){
                $position = $entity->getPosition();
                $saved[self::chunkKey($position->getChunkX(), $position->getChunkZ())][] = $tag;
            }
        }

        return $saved;
    }

    /**
     * Hands $onReady the chunk once it is there: right away when it is in memory or on disk,
     * later when it has to be generated and the generator does that on a worker.
     *
     * @param Closure(Chunk) : void $onReady
     */
    public function requestChunk(int $chunkX, int $chunkZ, Closure $onReady) : void{
        $key = self::chunkKey($chunkX, $chunkZ);

        if(isset($this->chunks[$key])){
            $onReady($this->chunks[$key]);
            return;
        }

        if(isset($this->generating[$key])){
            $this->generating[$key][] = $onReady;
            return;
        }

        $generator = $this->generator;

        if(!$generator instanceof AsyncGenerator){
            $onReady($this->loadChunk($chunkX, $chunkZ));
            return;
        }

        $chunk = $this->provider->loadChunk($chunkX, $chunkZ);

        if($chunk !== null){
            $this->chunks[$key] = $chunk;
            $this->onChunkLoaded($chunk, false);
            $onReady($chunk);
            return;
        }

        $this->generating[$key] = [$onReady];
        $chunk = new Chunk($chunkX, $chunkZ);

        $generator->generateChunkLater($chunk, function() use ($key, $chunk) : void{
            $waiting = $this->generating[$key] ?? [];
            unset($this->generating[$key]);

            if($this->closed){
                return;
            }

            $chunk->collectGarbage();
            $chunk->setChanged();

            if(!isset($this->chunks[$key])){
                $this->chunks[$key] = $chunk;
                $this->onChunkLoaded($chunk, true);
            }

            foreach($waiting as $onReady){
                $onReady($this->chunks[$key]);
            }
        });
    }

    /**
     * Loads every chunk within $radius chunks of the given chunk (a square). Chunks to be
     * generated are made side by side when the generator can do that.
     *
     * @return array{loaded: int, generated: int} chunks read from disk / newly generated
     */
    public function loadChunksAround(int $centerX, int $centerZ, int $radius) : array{
        if($radius < 0){
            throw new InvalidArgumentException("Radius cannot be negative");
        }

        $result = ["loaded" => 0, "generated" => 0];
        $pending = [];

        for($x = $centerX - $radius; $x <= $centerX + $radius; ++$x){
            for($z = $centerZ - $radius; $z <= $centerZ + $radius; ++$z){
                if($this->isChunkLoaded($x, $z)){
                    continue;
                }

                if(!$this->generator instanceof AsyncGenerator){
                    $this->loadChunk($x, $z, $generated);
                    ++$result[$generated ? "generated" : "loaded"];
                    continue;
                }

                $key = self::chunkKey($x, $z);
                $this->requestChunk($x, $z, static function(Chunk $chunk) : void{});

                if(isset($this->generating[$key])){
                    $pending[] = $key;
                    ++$result["generated"];
                }else{
                    ++$result["loaded"];
                }
            }
        }

        $this->waitForGeneration($pending);

        return $result;
    }

    /**
     * Blocks until the chunks are made, for when nothing can go on without them.
     *
     * @param list<string> $keys
     */
    private function waitForGeneration(array $keys) : void{
        foreach($keys as $key){
            while(isset($this->generating[$key])){
                if(Native::poll() === 0){
                    usleep(200);
                }
            }
        }
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

        (new ChunkUnloadEvent($this, $chunk))->call();

        $saved = [];

        foreach($this->entities as $entity){
            $position = $entity->getPosition();

            if($entity instanceof Player || $entity->isClosed() || $position->getChunkX() !== $chunkX || $position->getChunkZ() !== $chunkZ){
                continue;
            }

            $tag = $entity->save();

            if($tag !== null){
                $saved[] = $tag;
            }

            $entity->close();
        }

        if($saved !== [] || $chunk->getSavedEntities() !== []){
            $chunk->setSavedEntities($saved);
            $chunk->setChanged();
        }

        if($chunk->hasChanged()){
            $this->provider->saveChunk($chunk);
        }

        $this->mobEngine->setChunk($chunkX, $chunkZ, null);
        $this->redstoneUpdater->forgetChunk($chunkX, $chunkZ);
        $this->light->removeChunk($chunkX, $chunkZ);
        $this->liquids->removeChunk($chunkX, $chunkZ);
        unset($this->chunks[$key], $this->chunkLastUsed[$key], $this->tickingChunks[$key]);
    }

    /**
     * Writes every changed chunk to disk.
     *
     * @return int number of chunks written
     */
    public function save() : int{
        (new WorldSaveEvent($this))->call();
        $saved = 0;
        $entities = $this->saveEntities();

        foreach($this->chunks as $key => $chunk){
            $inChunk = $entities[$key] ?? [];

            if($inChunk !== [] || $chunk->getSavedEntities() !== []){
                $chunk->setSavedEntities($inChunk);
                $chunk->setChanged();
            }

            if($chunk->hasChanged()){
                $this->provider->saveChunk($chunk);
                $chunk->setChanged(false);
                ++$saved;
            }
        }

        $this->provider->saveMetadata($this->metadata);
        return $saved;
    }

    /**
     * Saves everything and releases the provider. The world must not be used afterward.
     *
     * @return int number of chunks written
     */
    public function close() : int{
        if($this->closed){
            return 0;
        }

        $saved = $this->save();
        $this->generating = [];
        $this->provider->close();
        $this->mobEngine->close();
        $this->light->close();
        $this->liquids->close();
        $this->chunks = [];
        $this->closed = true;
        return $saved;
    }

    public function getBiome(int $x, int $y, int $z) : Biome{
        return BiomeRegistry::get($this->loadChunk($x >> 4, $z >> 4)->getBiome($x & 15, $y, $z & 15));
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

        if(!$oldState->equals($block)){
            $this->light->update($x, $y, $z);
        }

        foreach($this->getPlayersViewing($x >> 4, $z >> 4) as $player){
            $player->getNetworkSession()->sendBlock($x, $y, $z, $block);
        }

        if(!$oldState->equals($block)){
            $this->redstoneUpdater->onBlockChanged($x, $y, $z, $oldState, $block);
            $this->randomTicker->onBlockChanged($x, $y, $z, $oldState, $block);
            $this->liquids->onBlockChanged($x, $y, $z);
            $this->blockTicker->onBlockChanged($x, $y, $z, $oldState, $block);
            BlockSupport::onBlockChanged($this, $x, $y, $z, $oldState, $block);
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
    public function scheduleUpdate(int $delay, Closure $update, ?int $x = null, ?int $z = null) : void{
        $this->scheduledUpdates[] = [$this->currentTick + $delay, $update, $x, $z];
    }

    public function getSpawnPosition() : Vector3{
        if($this->metadata->spawn !== null){
            return $this->metadata->spawn;
        }

        $spawn = $this->generator->getSpawn();

        if($spawn !== null){
            $this->setSpawnPosition($spawn);
            return $spawn;
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
        $old = $this->metadata->spawn;
        $this->metadata->spawn = $position;
        $this->provider->saveMetadata($this->metadata);
        (new SpawnChangeEvent($this, $old))->call();
    }

    /**
     * @internal
     */
    public function addEntity(Entity $entity) : void{
        $this->entities[$entity->getId()] = $entity;

        if($entity instanceof Player){
            $this->players[$entity->getId()] = $entity;
        }else{
            $this->spawning[$entity->getId()] = $entity;
        }
    }

    /**
     * @internal
     */
    public function removeEntity(Entity $entity) : void{
        unset($this->entities[$entity->getId()], $this->players[$entity->getId()]);
        unset($this->entities[$entity->getId()], $this->players[$entity->getId()], $this->spawning[$entity->getId()]);
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
        ++$this->metadata->gameTime;
        $this->updateTickingChunks();

        if($this->gameRules->getBool(GameRules::DO_DAYLIGHT_CYCLE)){
            ++$this->metadata->time;

            if($this->metadata->gameTime % self::TIME_SYNC_TICKS === 0){
                $this->broadcastToPlayers(SetTimePacket::create($this->metadata->time));
            }
        }

        foreach($this->tickingChunks as $chunk){
            foreach($chunk->getBlockEntities() as $entity){
                if($entity instanceof TickableBlockEntity){
                    $entity->tick($this, $currentTick);
                }

                if($entity instanceof ContainerBlockEntity && $entity->takeContentsChanged()){
                    $this->redstoneUpdater->update($entity->getX(), $entity->getY(), $entity->getZ());
                }
            }
        }

        $this->redstoneUpdater->tick($currentTick);
        $this->randomTicker->tick();
        $this->liquids->tick($currentTick);
        $this->blockTicker->tick($currentTick);
        $this->weather->tick($this->randomTicker->getTickingChunks());
        $this->sleeping->tick();
        NaturalSpawner::tick($this);

        foreach($this->scheduledUpdates as $key => [$tick, $update, $x, $z]){
            if($tick > $currentTick){
                continue;
            }

            if($x !== null && $z !== null && !$this->isChunkTicking($x >> 4, $z >> 4)){
                $this->scheduledUpdates[$key][0] = $currentTick + self::FROZEN_DELAY;
                continue;
            }

            unset($this->scheduledUpdates[$key]);
            $update();
        }

        if($this->spawning !== []){
            $spawning = $this->spawning;
            $this->spawning = [];

            foreach($spawning as $entity){
                if(!$entity->isClosed()){
                    (new EntitySpawnEvent($entity))->call();
                }
            }
        }

        foreach($this->entities as $entity){
            if($entity instanceof Player || $entity->isClosed()){
                continue;
            }

            $position = $entity->getPosition();

            if(isset($this->tickingChunks[self::chunkKey($position->getChunkX(), $position->getChunkZ())])){
                $entity->tick($currentTick);
            }
        }

        $this->mobEngine->tick();

        if($currentTick % self::UNLOAD_CHECK_TICKS === 0){
            $this->unloadUnusedChunks();
        }
    }

    /**
     * Drops an item at $position, flying off in a random direction unless $motion is given.
     */
    public function dropItem(Vector3 $position, Item $item, ?Vector3 $motion = null, int $pickupDelay = ItemEntity::DEFAULT_PICKUP_DELAY) : ?ItemEntity{
        if($item->isNull()){
            return null;
        }

        if(ItemSpawnEvent::hasHandlers()){
            $event = new ItemSpawnEvent($this, $position, $item);
            $event->call();
            $item = $event->getItem();

            if($event->isCancelled() || $item->isNull()){
                return null;
            }
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