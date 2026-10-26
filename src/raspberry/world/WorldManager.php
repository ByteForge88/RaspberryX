<?php

declare(strict_types=1);

namespace raspberry\world;

use raspberry\utils\Logger;

use raspberry\world\format\io\RaspberryWorldProvider;
use raspberry\world\format\io\WorldMetadata;
use raspberry\world\generator\GeneratorFactory;

use Symfony\Component\Filesystem\Path;

use InvalidArgumentException;
use LogicException;

use function array_values;
use function count;
use function preg_match;
use function strtolower;
use function time;

/**
 * Owns every loaded world. Worlds live in <data>/worlds/<name>/.
 */
class WorldManager{

    private string $worldsPath;

    private GeneratorFactory $generatorFactory;

    private string $blockDataVersion;

    private Logger $logger;

    /** @var array<string, World> keyed by lower-case name */
    private array $worlds = [];

    private ?World $defaultWorld = null;

    public function __construct(
        string $worldsPath,
        GeneratorFactory $generatorFactory,
        string $blockDataVersion,
        Logger $logger
    ){
        $this->worldsPath = $worldsPath;
        $this->generatorFactory = $generatorFactory;
        $this->blockDataVersion = $blockDataVersion;
        $this->logger = $logger;
    }

    public function getWorldsPath() : string{
        return $this->worldsPath;
    }

    /**
     * Whether a saved world with this name exists on disk.
     */
    public function worldExists(string $name) : bool{
        return self::isValidName($name) && RaspberryWorldProvider::exists($this->worldPath($name));
    }

    /**
     * Loads a saved world. Its generator comes from the world's own metadata.
     */
    public function loadWorld(string $name) : World{
        $this->checkNotLoaded($name);

        $provider = new RaspberryWorldProvider($this->worldPath($name), $this->logger);
        $metadata = $provider->loadMetadata();

        $generator = $this->generatorFactory->create($metadata->generator, $metadata->generatorSettings);

        return $this->worlds[strtolower($name)] = new World($metadata, $generator, $provider);
    }

    /**
     * Creates a new, empty world on disk. Chunks generate as they are loaded.
     */
    public function createWorld(string $name, string $generatorName, string $generatorSettings = "") : World{
        $this->checkNotLoaded($name);

        if($this->worldExists($name)){
            throw new LogicException("World '{$name}' already exists on disk");
        }

        //create the generator first, so invalid settings fail before anything is written
        $generator = $this->generatorFactory->create($generatorName, $generatorSettings);

        $metadata = new WorldMetadata($name, $generatorName, $generatorSettings, time(), $this->blockDataVersion);

        $provider = new RaspberryWorldProvider($this->worldPath($name), $this->logger);
        $provider->saveMetadata($metadata);

        return $this->worlds[strtolower($name)] = new World($metadata, $generator, $provider);
    }

    public function getWorld(string $name) : ?World{
        return $this->worlds[strtolower($name)] ?? null;
    }

    public function isWorldLoaded(string $name) : bool{
        return isset($this->worlds[strtolower($name)]);
    }

    /**
     * @return list<World>
     */
    public function getWorlds() : array{
        return array_values($this->worlds);
    }

    public function getWorldCount() : int{
        return count($this->worlds);
    }

    public function hasDefaultWorld() : bool{
        return $this->defaultWorld !== null;
    }

    public function getDefaultWorld() : World{
        return $this->defaultWorld ?? throw new LogicException("No default world has been set");
    }

    public function setDefaultWorld(World $world) : void{
        if(($this->worlds[strtolower($world->getName())] ?? null) !== $world){
            throw new InvalidArgumentException("World '{$world->getName()}' is not managed by this WorldManager");
        }

        $this->defaultWorld = $world;
    }

    /**
     * Saves changed chunks of every loaded world.
     *
     * @return int number of chunks written
     */
    public function saveAll() : int{
        $saved = 0;

        foreach($this->worlds as $world){
            $saved += $world->save();
        }

        return $saved;
    }

    /**
     * Saves and unloads a world. The default world can only be unloaded by close().
     *
     * @return int number of chunks written
     */
    public function unloadWorld(World $world) : int{
        if($world === $this->defaultWorld){
            throw new LogicException("The default world cannot be unloaded");
        }

        $key = strtolower($world->getName());

        if(($this->worlds[$key] ?? null) !== $world){
            throw new InvalidArgumentException("World '{$world->getName()}' is not managed by this WorldManager");
        }

        unset($this->worlds[$key]);

        return $world->close();
    }

    /**
     * Saves and unloads every world, including the default one (server shutdown).
     *
     * @return int number of chunks written
     */
    public function close() : int{
        $saved = 0;

        foreach($this->worlds as $world){
            $saved += $world->close();
        }

        $this->worlds = [];
        $this->defaultWorld = null;

        return $saved;
    }

    /**
     * World names are folder names, so only allow characters that are safe on Windows and Linux.
     */
    public static function isValidName(string $name) : bool{
        return preg_match("/^[A-Za-z0-9][A-Za-z0-9 _-]{0,63}$/D", $name) === 1;
    }

    private function checkNotLoaded(string $name) : void{
        if(!self::isValidName($name)){
            throw new InvalidArgumentException("Invalid world name '{$name}': use 1-64 letters, digits, spaces, '_' or '-', starting with a letter or digit");
        }

        if($this->isWorldLoaded($name)){
            throw new LogicException("World '{$name}' is already loaded");
        }
    }

    private function worldPath(string $name) : string{
        return Path::join($this->worldsPath, $name);
    }
}