<?php

declare(strict_types=1);

namespace raspberry\plugin;

use raspberry\event\Event;
use raspberry\event\EventManager;
use raspberry\event\EventPriority;
use raspberry\event\Listener;
use raspberry\event\RegisteredListener;
use raspberry\event\plugin\PluginDisableEvent;
use raspberry\event\plugin\PluginEnableEvent;

use raspberry\command\Command;

use raspberry\Server;
use raspberry\ServerInfo;

use Symfony\Component\Filesystem\Path;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

use Closure;
use Phar;
use Throwable;
use UnexpectedValueException;

use function array_reverse;
use function class_exists;
use function file_exists;
use function file_get_contents;
use function realpath;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function is_subclass_of;
use function mkdir;
use function scandir;
use function spl_autoload_register;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;

class PluginManager{

    //TODO: Allow multiple API versions
    public const string API_VERSION = ServerInfo::PLUGIN_API;

    private Server $server;

    private string $dataPath;

    private EventManager $eventManager;

    /** @var array<string, PluginBase> */
    private array $plugins = [];

    /** @var array<string, true> */
    private array $deferred = [];

    /** @var array<string, list<PluginCommand>> */
    private array $registeredCommands = [];

    public function __construct(Server $server, string $dataPath){
        $this->server = $server;
        $this->dataPath = $dataPath;
        $this->eventManager = new EventManager($server->getLogger());
    }

    public function getEventManager() : EventManager{
        return $this->eventManager;
    }

    public function loadPlugins(string $directory) : void{
        if(!is_dir($directory)){
            mkdir($directory, 0777, true);
            return;
        }

        /** @var array<string, array{PluginDescription, string}> $found */
        $found = [];

        foreach(scandir($directory) ?: [] as $entry){
            $file = Path::join($directory, $entry);

            if(!is_file($file) || strtolower(Path::getExtension($entry)) !== "phar"){
                continue;
            }

            try{
                [$description, $folder] = self::readPhar($file);
            }catch(PluginException $exception){
                $this->error($entry, $exception->getMessage());
                continue;
            }

            $key = strtolower($description->getName());

            if(isset($found[$key]) || isset($this->plugins[$key])){
                $this->error($description->getName(), "there is another plugin with this name");
                continue;
            }

            $found[$key] = [$description, $folder];
        }

        foreach($this->sortByDependencies($found) as [$description, $folder]){
            $this->loadPlugin($description, $folder);
        }
    }

    /**
     * Enables the loaded plugins that start at $order, in load order.
     */
    public function enablePlugins(PluginLoadOrder $order) : void{
        foreach($this->plugins as $key => $plugin){
            if(!$plugin->isEnabled() && ($plugin->getDescription()->getOrder() === $order || isset($this->deferred[$key]))){
                if($order === PluginLoadOrder::STARTUP && $this->needsLaterPlugin($plugin)){
                    $this->deferred[$key] = true;
                    continue;
                }

                unset($this->deferred[$key]);
                $this->enablePlugin($plugin);
            }
        }
    }

    public function enablePlugin(PluginBase $plugin) : void{
        if($plugin->isEnabled()){
            return;
        }

        foreach($plugin->getDescription()->getDepend() as $dependency){
            if(!$this->isPluginEnabled($dependency)){
                $this->error($plugin->getName(), "it needs {$dependency}, which is not enabled");
                return;
            }
        }

        $language = $this->server->getLanguage();
        $this->server->getLogger()->info($language->translate("plugin.enabling", ["plugin" => $plugin->getDescription()->getFullName()]));

        $this->registerCommands($plugin);

        try{
            $plugin->setEnabled(true);
        }catch(Throwable $exception){
            $this->server->getLogger()->error($language->translate("plugin.enableError", ["plugin" => $plugin->getName(), "reason" => $exception::class . ": " . $exception->getMessage()]));
            $this->server->getLogger()->debug($exception->getTraceAsString());
            $this->disablePlugin($plugin);
            return;
        }

        (new PluginEnableEvent($plugin))->call();
    }

    public function disablePlugin(PluginBase $plugin) : void{
        if($plugin->isEnabled()){
            (new PluginDisableEvent($plugin))->call();
            $this->server->getLogger()->info($this->server->getLanguage()->translate("plugin.disabling", ["plugin" => $plugin->getDescription()->getFullName()]));

            try{
                $plugin->setEnabled(false);
            }catch(Throwable $exception){
                $this->server->getLogger()->error("Error while disabling {$plugin->getName()}: " . $exception::class . ": " . $exception->getMessage());
                $this->server->getLogger()->debug($exception->getTraceAsString());
            }
        }

        $plugin->getScheduler()->cancelAllTasks();
        $this->eventManager->unregisterAll($plugin);
        $this->unregisterCommands($plugin);
    }

    public function disablePlugins() : void{
        foreach(array_reverse($this->plugins) as $plugin){
            $this->disablePlugin($plugin);
        }
    }

    /**
     * @internal
     */
    public function tick(int $currentTick) : void{
        foreach($this->plugins as $plugin){
            if($plugin->isEnabled()){
                $plugin->getScheduler()->tick($currentTick);
            }
        }
    }

    public function getPlugin(string $name) : ?PluginBase{
        return $this->plugins[strtolower($name)] ?? null;
    }

    /**
     * @return array<string, PluginBase>
     */
    public function getPlugins() : array{
        return $this->plugins;
    }

    public function isPluginEnabled(string $name) : bool{
        return $this->getPlugin($name)?->isEnabled() ?? false;
    }

    public function registerEvents(Listener $listener, PluginBase $plugin) : void{
        $this->eventManager->registerEvents($listener, $plugin);
    }

    /**
     * @param class-string<Event> $event
     * @param Closure(Event) : void $handler
     */
    public function registerEvent(string $event, Closure $handler, PluginBase $plugin, EventPriority $priority = EventPriority::NORMAL, bool $handleCancelled = false) : RegisteredListener{
        return $this->eventManager->register($event, $handler, $plugin, $priority, $handleCancelled);
    }

    public function callEvent(Event $event) : void{
        $this->eventManager->call($event);
    }

    private function needsLaterPlugin(PluginBase $plugin) : bool{
        foreach($plugin->getDescription()->getDepend() as $dependency){
            $other = $this->getPlugin($dependency);

            if($other !== null && ($other->getDescription()->getOrder() === PluginLoadOrder::POSTWORLD || $this->needsLaterPlugin($other))){
                return true;
            }
        }

        return false;
    }

    private function loadPlugin(PluginDescription $description, string $folder) : void{
        $name = $description->getName();

        if(!$description->isCompatibleWith(self::API_VERSION)){
            $this->error($name, "it is made for API " . implode(", ", $description->getApi()) . ", this server has " . self::API_VERSION);
            return;
        }

        foreach($description->getDepend() as $dependency){
            if($this->getPlugin($dependency) === null){
                $this->error($name, "it needs {$dependency}, which is not there");
                return;
            }
        }

        $this->server->getLogger()->info($this->server->getLanguage()->translate("plugin.loading", ["plugin" => $description->getFullName()]));
        self::registerAutoloader($description, Path::join($folder, "src"));

        $main = $description->getMain();

        if(!class_exists($main)){
            $this->error($name, "the main class {$main} is not in the .phar's src/");
            return;
        }

        if(!is_subclass_of($main, PluginBase::class)){
            $this->error($name, "the main class {$main} does not extend " . PluginBase::class);
            return;
        }

        try{
            $this->server->getPermissionManager()->registerAll($description->getPermissions());
        }catch(Throwable $exception){
            $this->error($name, "bad permissions in plugin.yml: " . $exception->getMessage());
            return;
        }

        try{
            $plugin = new $main($this->server, $description, $folder, Path::join($this->dataPath, $name));
            $plugin->load();
        }catch(Throwable $exception){
            $this->error($name, $exception::class . ": " . $exception->getMessage());
            $this->server->getLogger()->debug($exception->getTraceAsString());
            return;
        }

        $this->plugins[strtolower($name)] = $plugin;
    }

    private function registerCommands(PluginBase $plugin) : void{
        $commandMap = $this->server->getCommandMap();

        foreach($plugin->getDescription()->getCommands() as $name => $properties){
            $command = new PluginCommand($name, $plugin, $properties["description"], $properties["permission"], $properties["aliases"], $properties["usage"]);
            $label = $name;

            if($commandMap->getCommand($label) !== null){
                $label = strtolower(str_replace(" ", "_", $plugin->getName())) . ":" . $name;
                $this->server->getLogger()->warning($this->server->getLanguage()->translate("plugin.commandTaken", ["plugin" => $plugin->getName(), "command" => $name, "label" => $label]));
            }

            try{
                $commandMap->registerCommand($label, $command);
                $this->registeredCommands[strtolower($plugin->getName())][] = $command;
            }catch(Throwable $exception){
                $this->error($plugin->getName(), "could not register /{$name}: " . $exception->getMessage());
            }
        }

        if($plugin->getDescription()->getCommands() !== []){
            $this->resendCommands();
        }
    }

    /**
     * Registers a command the plugin made itself. When its name is taken it gets the plugin's
     * name in front, like /urplugin:heal. It is removed when the plugin is disabled.
     *
     * @return string
     */
    public function registerCommand(PluginBase $plugin, Command $command, ?string $label = null) : string{
        if($command instanceof PluginOwned && $command->getOwningPlugin() !== $plugin){
            throw new PluginException("/{$command->getName()} belongs to {$command->getOwningPlugin()->getName()}, not {$plugin->getName()}");
        }

        $commandMap = $this->server->getCommandMap();
        $label = strtolower($label ?? $command->getName());

        if($commandMap->getCommand($label) !== null){
            $taken = $label;
            $label = strtolower(str_replace(" ", "_", $plugin->getName())) . ":" . $label;
            $this->server->getLogger()->warning($this->server->getLanguage()->translate("plugin.commandTaken", ["plugin" => $plugin->getName(), "command" => $taken, "label" => $label]));
        }

        $commandMap->registerCommand($label, $command);
        $this->registeredCommands[strtolower($plugin->getName())][] = $command;
        $this->resendCommands();
        return $label;
    }

    /**
     * Players online get the new list of commands for their suggestions.
     */
    private function resendCommands() : void{
        foreach($this->server->getInGamePlayers() as $player){
            $player->getNetworkSession()->sendAvailableCommands();
        }
    }

    private function unregisterCommands(PluginBase $plugin) : void{
        $commandMap = $this->server->getCommandMap();
        $removed = false;

        foreach($this->registeredCommands[strtolower($plugin->getName())] ?? [] as $command){
            $commandMap->unregisterCommand($command);
            $removed = true;
        }

        unset($this->registeredCommands[strtolower($plugin->getName())]);

        foreach($commandMap->getCommands() as $command){
            if($command instanceof PluginOwned && $command->getOwningPlugin() === $plugin){
                $commandMap->unregisterCommand($command);
                $removed = true;
            }
        }

        if($removed){
            $this->resendCommands();
        }
    }

    /**
     * @return array{PluginDescription, string}
     *
     * @throws PluginException
     */
    private static function readPhar(string $file) : array{
        try{
            $phar = new Phar($file);
        }catch(UnexpectedValueException $exception){
            throw new PluginException("it is not a valid .phar: " . $exception->getMessage());
        }

        if(!isset($phar["plugin.yml"])){
            throw new PluginException("there is no plugin.yml in the .phar");
        }

        unset($phar);

        $folder = "phar://" . Path::canonicalize(realpath($file) ?: $file);
        $contents = file_get_contents(Path::join($folder, "plugin.yml"));

        if($contents === false){
            throw new PluginException("plugin.yml cannot be read");
        }

        try{
            $data = Yaml::parse($contents);
        }catch(ParseException $exception){
            throw new PluginException("plugin.yml is not valid YAML: " . $exception->getMessage());
        }

        if(!is_array($data)){
            throw new PluginException("plugin.yml is empty");
        }

        if(!is_dir(Path::join($folder, "src"))){
            throw new PluginException("there is no src/ folder in the .phar");
        }

        return [new PluginDescription($data), $folder];
    }

    private static function registerAutoloader(PluginDescription $description, string $src) : void{
        $prefix = $description->getSrcNamespacePrefix();
        $prefix = $prefix === "" ? "" : $prefix . "\\";

        spl_autoload_register(function(string $class) use ($prefix, $src) : void{
            if($prefix !== "" && !str_starts_with($class, $prefix)){
                return;
            }

            $file = Path::join($src, str_replace("\\", "/", substr($class, strlen($prefix))) . ".php");

            if(file_exists($file)){
                require $file;
            }
        });
    }

    /**
     * @param array<string, array{PluginDescription, string}> $found
     *
     * @return list<array{PluginDescription, string}>
     */
    private function sortByDependencies(array $found) : array{
        $after = [];

        foreach($found as $key => [$description]){
            foreach([...$description->getDepend(), ...$description->getSoftDepend()] as $dependency){
                if(isset($found[strtolower($dependency)])){
                    $after[$key][] = strtolower($dependency);
                }
            }

            foreach($description->getLoadBefore() as $later){
                if(isset($found[strtolower($later)])){
                    $after[strtolower($later)][] = $key;
                }
            }
        }

        $sorted = [];
        $state = [];

        $visit = function(string $key, array $path) use (&$visit, &$sorted, &$state, $found, $after) : bool{
            if(($state[$key] ?? 0) === 2){
                return true;
            }

            if(($state[$key] ?? 0) === 1){
                $this->error($found[$key][0]->getName(), "its dependencies go round in a circle: " . implode(" -> ", [...$path, $found[$key][0]->getName()]));
                return false;
            }

            $state[$key] = 1;

            foreach($after[$key] ?? [] as $dependency){
                if(!$visit($dependency, [...$path, $found[$key][0]->getName()])){
                    $state[$key] = 2;
                    return false;
                }
            }

            $state[$key] = 2;
            $sorted[] = $found[$key];

            return true;
        };

        foreach($found as $key => $_){
            $visit($key, []);
        }

        return $sorted;
    }

    private function error(string $plugin, string $reason) : void{
        $this->server->getLogger()->error($this->server->getLanguage()->translate("plugin.loadError", ["plugin" => $plugin, "reason" => $reason]));
    }
}