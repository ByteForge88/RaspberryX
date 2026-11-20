# Plugins:
RaspberryX plugins are written in PHP and packed into a .phar file. If you made PocketMine-MP plugins before some things will look familiar, but the API is different so PocketMine plugins will NOT work here.

This is still **ALPHA** so the API can change between versions.

# Getting started:
A plugin is a folder that looks like this:
```
HelloWorld/
 - plugin.yml
 - src/
    - Main.php
    - command/HealCommand.php
 - resources/
    - config.yml
```

`src/` has your classes and `resources/` has files you want to ship with the plugin (config, lang files ect). `resources/` is optional.

# plugin.yml:
This tells the server what your plugin is.
```yml
name: HelloWorld
main: hello\Main
version: 1.0.0
api: 1.0.0
src-namespace-prefix: hello
description: Says hi to players
author: you

commands:
  hello:
    description: Says hello back
    usage: /hello [name]
    permission: helloworld.command.hello

permissions:
  helloworld.command.hello:
    description: Lets you use /hello
    default: true
  helloworld.command.heal:
    description: Lets you use /heal
    default: op
```

| Key | What it does |
| --- | --- |
| `name` | Your plugin's name, letters, digits, spaces, `_`, `.` and `-` only |
| `main` | Your main class with its namespace, it can't be in the `raspberry` namespace |
| `version` | Your plugin's version |
| `api` | The server API versions your plugin is made for, it loads when the major version is the same and the server isn't older |
| `src-namespace-prefix` | The namespace of the classes right in `src/`, leave it out if `src/` has the namespace folders in it |
| `description`, `author`, `authors`, `website` | Info about your plugin |
| `depend` | Plugins that have to be there and enabled first |
| `softdepend` | Plugins that get enabled first if they are there |
| `loadbefore` | Plugins your plugin gets enabled before |
| `load` | `POSTWORLD` (default) or `STARTUP` if you need to be enabled before the world loads |
| `commands` | Commands with their description, usage, permission and aliases |
| `permissions` | Your permissions, `default` can be `true`, `op` or `console` |

# The main class:
Your main class extends `PluginBase`. You don't need all of these, just the ones you use.
```php
<?php

declare(strict_types=1);

namespace hello;

use hello\command\HealCommand;

use raspberry\command\CommandSender;

use raspberry\event\Listener;
use raspberry\event\Priority;
use raspberry\event\EventPriority;
use raspberry\event\block\BlockBreakEvent;
use raspberry\event\player\PlayerJoinEvent;

use raspberry\plugin\PluginBase;
use raspberry\plugin\PluginCommand;

use raspberry\scheduler\ClosureTask;

class Main extends PluginBase implements Listener{

    protected function onEnable() : void{
        $this->saveDefaultConfig();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->registerCommand("heal", new HealCommand($this));

        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function() : void{
            $this->getServer()->broadcastMessage("Remember to drink water");
        }), 20 * 60 * 5);

        $this->getLogger()->info("HelloWorld is on");
    }

    public function onJoin(PlayerJoinEvent $event) : void{
        $event->getPlayer()->sendMessage($this->getConfig()->getString("join-message"));
    }

    #[Priority(EventPriority::HIGH)]
    public function onBreak(BlockBreakEvent $event) : void{
        if($event->getBlock()->getName() === "minecraft:bedrock"){
            $event->cancel();
        }
    }

    public function onCommand(CommandSender $sender, PluginCommand $command, array $args) : bool{
        if($command->getName() === "hello"){
            $sender->sendMessage("Hello " . ($args[0] ?? $sender->getName()) . "!");
            return true;
        }

        return false;
    }
}
```

- `onLoad()` runs when your plugin is loaded, before any plugin is enabled
- `onEnable()` runs when your plugin is turned on, this is where you register stuff
- `onDisable()` runs when the server stops or your plugin gets disabled. Your tasks, listeners and commands get removed for you after it

# Events:
Make a class implement `Listener` and register it with `registerEvents()`. Every public method that takes one event is a handler, the name of the method doesn't matter.

- `#[Priority(EventPriority::HIGH)]` changes when your handler runs. It goes `LOWEST`, `LOW`, `NORMAL` (default), `HIGH`, `HIGHEST` then `MONITOR`. Only look at the event in `MONITOR`, don't change it
- `#[HandleCancelled]` makes your handler get events another plugin already cancelled
- Events that can be cancelled have `cancel()`, `uncancel()` and `isCancelled()`

You can also register one handler with a closure:
```php
$this->getServer()->getPluginManager()->registerEvent(PlayerJoinEvent::class, function(PlayerJoinEvent $event) : void{
    $event->setJoinMessage(null);
}, $this);
```

### Every event right now
- **block**: BlockBreakEvent, BlockBurnEvent, BlockChangeEvent, BlockDetachEvent, BlockFadeEvent, BlockFallEvent, BlockFormEvent, BlockGrowEvent, BlockIgniteEvent, BlockPlaceEvent, BlockSpreadEvent, LeavesDecayEvent, SignChangeEvent, StructureGrowEvent
- **entity**: EntityBlockChangeEvent, EntityCombustEvent, EntityDamageEvent, EntityDeathEvent, EntityDespawnEvent, EntityEffectAddEvent, EntityEffectRemoveEvent, EntityRegainHealthEvent, EntitySpawnEvent, EntityTeleportEvent, EntityTrampleFarmlandEvent, ExplosionPrimeEvent, ItemSpawnEvent
- **inventory**: CraftItemEvent, FurnaceBurnEvent, FurnaceSmeltEvent, InventoryCloseEvent, InventoryOpenEvent, InventoryTransactionEvent
- **player**: PlayerBedEnterEvent, PlayerBedLeaveEvent, PlayerBucketEmptyEvent, PlayerBucketFillEvent, PlayerChatEvent, PlayerCommandEvent, PlayerDeathEvent, PlayerDropItemEvent, PlayerExhaustEvent, PlayerExperienceChangeEvent, PlayerGameModeChangeEvent, PlayerInteractEntityEvent, PlayerInteractEvent, PlayerItemConsumeEvent, PlayerItemHeldEvent, PlayerItemUseEvent, PlayerJoinEvent, PlayerJumpEvent, PlayerKickEvent, PlayerLoginEvent, PlayerMoveEvent, PlayerPickupItemEvent, PlayerPreLoginEvent, PlayerQuitEvent, PlayerRespawnEvent, PlayerToggleFlightEvent, PlayerToggleGlideEvent, PlayerToggleSneakEvent, PlayerToggleSprintEvent, PlayerToggleSwimEvent
- **plugin**: PluginEnableEvent, PluginDisableEvent
- **server**: DataPacketReceiveEvent, DataPacketSendEvent, ServerCommandEvent
- **world**: ChunkLoadEvent, ChunkUnloadEvent, ExplosionEvent, LightningStrikeEvent, SpawnChangeEvent, WeatherChangeEvent, WorldLoadEvent, WorldSaveEvent, WorldUnloadEvent

They're all in `raspberry\event\<folder>`, like `raspberry\event\player\PlayerJoinEvent`.

# Commands:
There's two ways to make a command.

**1. In plugin.yml**: put it under `commands:` and handle it in `onCommand()` in your main class like `/hello` above. `$args` is the words typed after the command. Return `false` to show the usage.

**2. Your own command class**: extend `PluginOwnedCommand` and register it with `registerCommand()`. This way you get real Bedrock command arguments, so players get autocomplete and the client checks the types for you.
```php
<?php

declare(strict_types=1);

namespace hello\command;

use raspberry\command\CommandOutput;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\plugin\PluginBase;
use raspberry\plugin\PluginOwnedCommand;

use raspberry\player\Player;

class HealCommand extends PluginOwnedCommand{

    public function __construct(PluginBase $plugin){
        parent::__construct($plugin, "heal", "Heals players", "helloworld.command.heal");

        $this->addOverload(CommandParameter::target("player", true));
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        /** @var list<Player> $players */
        $players = $args["player"] ?? ($sender instanceof Player ? [$sender] : []);

        foreach($players as $player){
            $player->setHealth($player->getMaxHealth());
            $output->successText("Healed " . $player->getName());
        }
    }
}
```

- Each `addOverload()` is one way to type the command. Parameters include `int`, `float`, `string`, `bool`, `target`, `position`, `blockPosition`, `message`, `json`, `enum`, `postfix` (like `5L`) ect, pass `true` to make one optional
- A `target` gives you a list of players, so `@a` and `@p` work
- Send results with `$output->successText()` and `$output->errorText()`
- If another plugin already has the name, yours becomes `/yourplugin:heal`

# Scheduler:
Tasks run on the server's ticks, 20 ticks is 1 second.
```php
$scheduler = $this->getScheduler();

$scheduler->scheduleTask($task);                          //next tick
$scheduler->scheduleDelayedTask($task, 20 * 5);           //in 5 seconds
$scheduler->scheduleRepeatingTask($task, 20);             //every second
$scheduler->scheduleDelayedRepeatingTask($task, 100, 20); //every second after 5 seconds
```
Use `new ClosureTask(fn() => ...)` for small stuff, or extend `Task` and put your code in `onRun()`. Every schedule gives you a `TaskHandler`, call `cancel()` on it to stop the task. `onCancel()` runs when it's cancelled, also when your plugin gets disabled.

# Config and files:
- `getConfig()` gives you `config.yml` from your data folder. The first time it gets copied from `resources/config.yml`
- Config has `get()`, `getString()`, `getInt()`, `getBool()`, `getArray()`, `set()`, `remove()` and `save()`
- `saveResource("lang/en.yml")` copies any file from `resources/` to your data folder
- `getDataFolder()` is `plugin_data/<YourPlugin>/`, put the files your plugin makes there. You can't write inside your .phar
- `reloadConfig()` reads the config again if someone changed it while the server is running

# Building and installing:
Build your plugin into a .phar:
```
composer make-plugin -- path/to/HelloWorld
```
It checks your plugin.yml the same way the server does so a broken one never gets built, then puts `HelloWorld.phar` next to your plugin folder. You can give it an output folder as the second argument.

Drop the .phar into the server's `plugins/` folder and restart. The server only loads plugins from .phar files, not folders.

# Logging:
`$this->getLogger()` has `info()`, `warning()`, `error()` and `debug()`. Your messages show up with your plugin's name in front, like `[HelloWorld] HelloWorld is on`.