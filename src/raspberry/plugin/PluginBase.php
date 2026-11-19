<?php

declare(strict_types=1);

namespace raspberry\plugin;

use raspberry\command\Command;
use raspberry\command\CommandSender;

use raspberry\scheduler\TaskScheduler;

use raspberry\utils\Config;

use raspberry\Server;

use Symfony\Component\Filesystem\Path;

use SplFileInfo;
use InvalidArgumentException;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;

use function copy;
use function dirname;
use function file_exists;
use function is_dir;
use function is_file;
use function is_string;
use function mkdir;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

abstract class PluginBase implements CommandExecutor{

    private Server $server;

    private PluginDescription $description;

    private string $folder;

    private string $dataFolder;

    private PluginLogger $logger;

    private TaskScheduler $scheduler;

    private bool $enabled = false;

    private ?Config $config = null;

    public function __construct(Server $server, PluginDescription $description, string $folder, string $dataFolder){
        $this->server = $server;
        $this->description = $description;
        $this->folder = $folder;
        $this->dataFolder = $dataFolder;
        $this->logger = new PluginLogger($server->getLogger(), $description->getName());
        $this->scheduler = new TaskScheduler($this->logger, $server->getCurrentTick());
    }

    /**
     * Called once the plugin is loaded, before any plugin is enabled.
     */
    protected function onLoad() : void{

    }

    protected function onEnable() : void{

    }

    /**
     * Called when the server stops or the plugin is disabled. Its tasks, listeners and commands
     * are removed afterwards.
     */
    protected function onDisable() : void{

    }

    public function onCommand(CommandSender $sender, PluginCommand $command, array $args) : bool{
        return false;
    }

    /**
     * @internal
     */
    public function load() : void{
        $this->onLoad();
    }

    /**
     * @internal
     */
    public function setEnabled(bool $enabled) : void{
        if($this->enabled === $enabled){
            return;
        }

        $this->enabled = $enabled;

        if($enabled){
            $this->onEnable();
        }else{
            $this->onDisable();
        }
    }

    public function isEnabled() : bool{
        return $this->enabled;
    }

    public function getServer() : Server{
        return $this->server;
    }

    public function getDescription() : PluginDescription{
        return $this->description;
    }

    public function getName() : string{
        return $this->description->getName();
    }

    public function getLogger() : PluginLogger{
        return $this->logger;
    }

    public function getScheduler() : TaskScheduler{
        return $this->scheduler;
    }

    /**
     * The inside of the plugin's .phar, a phar:// path with plugin.yml, src/ and resources/.
     * Nothing can be written there, files the plugin makes go in getDataFolder().
     */
    public function getFolder() : string{
        return $this->folder;
    }

    /**
     * The plugin's .phar on disk.
     */
    public function getFile() : string{
        return str_starts_with($this->folder, "phar://") ? substr($this->folder, 7) : $this->folder;
    }

    /**
     * @return string
     */
    public function registerCommand(string $label, Command $command) : string{
        return $this->server->getPluginManager()->registerCommand($this, $command, $label);
    }

    /**
     * @param array<int|string, Command> $commands
     */
    public function registerCommands(array $commands) : void{
        foreach($commands as $label => $command){
            $this->registerCommand(is_string($label) ? $label : $command->getName(), $command);
        }
    }

    /**
     * Where the plugin keeps its config and other files, created on first use.
     */
    public function getDataFolder() : string{
        if(!is_dir($this->dataFolder)){
            mkdir($this->dataFolder, 0777, true);
        }

        return $this->dataFolder;
    }

    /**
     * The path of a file in resources/, or null when there is none.
     */
    public function getResource(string $name) : ?string{
        $path = $this->resourcePath($name);

        return is_file($path) ? $path : null;
    }

    /**
     * Every file in resources/, by its name there, like "config.yml" or "lang/en.yml".
     *
     * @return array<string, string> name => path
     */
    public function getResources() : array{
        $root = Path::join($this->folder, "resources");

        if(!is_dir($root)){
            return [];
        }

        $resources = [];

        /** @var SplFileInfo $file */
        foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file){
            if($file->isFile()){
                $path = Path::canonicalize($file->getPathname());
                $resources[substr($path, strlen(Path::canonicalize($root)) + 1)] = $path;
            }
        }

        return $resources;
    }

    /**
     * Copies a file from resources/ to the data folder, keeping one that is there already
     * unless $replace is true.
     *
     * @return bool
     */
    public function saveResource(string $name, bool $replace = false) : bool{
        $source = $this->getResource($name) ?? throw new InvalidArgumentException("{$this->getName()} has no resource \"{$name}\"");
        $target = Path::join($this->getDataFolder(), self::cleanName($name));

        if(!$replace && file_exists($target)){
            return false;
        }

        if(!is_dir(dirname($target))){
            mkdir(dirname($target), 0777, true);
        }

        return copy($source, $target);
    }

    /**
     * config.yml in the data folder, made from resources/config.yml the first time.
     */
    public function getConfig() : Config{
        if($this->config === null){
            $this->saveDefaultConfig();
            $this->config = new Config(Path::join($this->getDataFolder(), "config.yml"), Config::YAML);
        }

        return $this->config;
    }

    /**
     * Copies resources/config.yml to the data folder when there is no config.yml yet.
     */
    public function saveDefaultConfig() : bool{
        return $this->getResource("config.yml") !== null && $this->saveResource("config.yml");
    }

    public function saveConfig() : void{
        $this->getConfig()->save();
    }

    /**
     * Reads config.yml again, for changes made while the server runs.
     */
    public function reloadConfig() : void{
        $this->config = null;
        $this->getConfig();
    }

    private function resourcePath(string $name) : string{
        return Path::join($this->folder, "resources", self::cleanName($name));
    }

    /**
     * A resource name without ways out of the folder: "../x" is turned down.
     */
    private static function cleanName(string $name) : string{
        $name = trim(str_replace("\\", "/", $name), "/");
        $clean = Path::canonicalize($name);

        if($clean === "" || $clean === ".." || str_starts_with($clean, "../") || Path::isAbsolute($clean)){
            throw new InvalidArgumentException("Invalid resource name \"{$name}\"");
        }

        return $clean;
    }
}