<p align="center">
  <img src="logo/logo_with_background.png" alt="RaspberryX" width="320">
</p>

<p align="center">
  <b>A vanilla-first server for Minecraft: Bedrock Edition, written in PHP Rust and Python.</b>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/Minecraft-Bedrock%201.26.60-62B47A?style=flat-square" alt="Minecraft Bedrock 1.26.60">
  <img src="https://img.shields.io/badge/PHP-8.5-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP 8.5">
  <img src="https://img.shields.io/badge/Rust-native-DEA584?style=flat-square&logo=rust&logoColor=black" alt="Rust">
  <img src="https://img.shields.io/badge/Python-3-3776AB?style=flat-square&logo=python&logoColor=white" alt="Python 3">
  <img src="https://img.shields.io/badge/status-alpha-C2185B?style=flat-square" alt="Status: alpha">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-LGPL--3.0-blue?style=flat-square" alt="License: LGPL-3.0"></a>
</p>

---

# Introduction:
This software came into life when PocketMine-MP had announced that it will stop updating their services. Over the past months after its closure there were many forks of PocketMine-MP, but they really never solved the issues pocketmine had. Their goal was to optimize a poorly structured software. To each their own, but I want to solve the issues and while doing, so I also want to make it as vanilla as possible so you use this software as dedicated survival world.

**This project is NOT a fork of PocketMine-MP. RaspberryX is written from scratch, and although there are some similarities with class names but the API is different. Plugins from PocketMine-MP will NOT work here. refer to the [plugin docs](PLUGINS.md) to get started.**

# Description:
Written in PHP, Rust and Python. Each language as a crucial role in this project.

PHP: Mainly for plugin API

Rust: World/chunk, entity and redstone (RaspberryX doesn't use PHP workers instead that's Rust job)

Python: Writing/fetching and generating bedrock data

This project is still in **ALPHA** stages so not every feature is implemented.

# Getting started:
RaspberryX runs on 64-bit Windows and Linux. It needs PHP 8.5.12 or newer with the webrtc, ffi, openssl, zlib and phar extensions, and the native library for your system.

1. Download `RaspberryX-<version>.zip` from the [releases](https://github.com/ByteForge88/RaspberryX/releases) and unzip it into an empty folder.
2. Download the PHP build for your system from [RaspberryX-PHPBinaries](https://github.com/ByteForge88/RaspberryX-PHPBinaries) and unzip it into `bin/php` inside that folder.
3. Start the server:
    - Windows: double click `start.cmd`
    - Linux: run `chmod +x start.sh` once, then `./start.sh`
4. The setup wizard asks a few questions the first time and writes `server.properties`.
5. Join with Minecraft: Bedrock Edition 1.26.60.

The folder ends up like this:
```
RaspberryX.phar
start.cmd / start.sh
bin/php/...                       PHP from RaspberryX-PHPBinaries
bin/native/raspberry_native.dll   (Windows)
bin/native/libraspberry_native.so (Linux)
plugins/
worlds/
data/                             server identity
server.properties
```

# Building from source:
You need the PHP build above, [Composer](https://getcomposer.org) and [Rust](https://rustup.rs).

```
composer install
composer make-native
composer make-server
composer make-release
```

- `make-native` builds the Rust library into `bin/native` for the system you run it on (stop the server first, Windows locks the file).
- `make-server` builds `RaspberryX.phar`. Without it, `start.cmd` and `start.sh` run the server straight from `src`.
- `make-release` packs the phar, start scripts, licenses and every library in `bin/native` into `release/RaspberryX-<version>.zip`. Build the native library on Windows and on Linux and put both in `bin/native` before running it.
- `make-plugin` builds a plugin into a `.phar`, see the [plugin docs](PLUGINS.md).

# What's implemented:
### World
- Old, pre-normal, void and flat generators (biomes are climate based)
- Day and night cycle
- Weather: rain, snow and thunderstorms, snow layers and ice forming (lightning is partially done)
- Random block ticks, crops growing, saplings growing into trees, leaf decay and farmland (plants spreading is partially done)
- Ice, snow and frosted ice melting, copper oxidizing
- Water and lava flowing and mixing into obsidian, cobblestone, stone and basalt (flowing and bubble columns are partially done)
- Fire spreading, explosions and TNT
- Falling blocks like sand and gravel
- Light engine (sky light and block light)
- Blocks breaking when what holds them up is gone, neighbor updates
- Ticking areas and simulation distance

### Mobs
- Mob engine written in Rust: movement, pathfinding, goals, melee and ranged attacks, swimming, climbing, despawning
- Pig, cow, chicken, sheep and mooshroom (partially done)
- Zombie, husk, drowned, skeleton, stray, bogged, creeper, spider, cave spider, enderman, endermite, silverfish, slime and witch (some still partially done)
- Natural spawning from Mojang's spawn rules with mob caps, animals in new chunks (partially done)
- Monster spawners and spawn eggs
- Mobs burn in the sun, spiders climb walls, zombies turn into drowned, silverfish hide in infested blocks

### Player
- Movement, attacking, armor, gamemodes, hunger, health, exp and levels, pvp, death and respawning
- Survival block breaking with break times checked by the server
- Food, potions (splash and lingering too) and chorus fruit
- Item drops and exp orbs (orbs fly to you and Mending repairs your stuff with them)
- Arrows, snowballs, eggs and ender pearls
- Fishing with Bedrock's loot tables (fish, treasure, junk)
- Sleeping in beds and riding boats and minecarts (partially done)
- Effects (90% done)

### Entities
- Items, exp orbs, arrows, thrown items, splash and lingering potions with area effect clouds
- Fishing hooks, paintings, falling blocks and primed TNT
- Boats and minecarts (partially done)

### Enchantments
- Sharpness, Smite, Bane of Arthropods, Knockback, Fire Aspect, Looting
- Efficiency, Fortune, Silk Touch, Unbreaking, Mending
- Protection types, Thorns, Feather Falling, Respiration, Aqua Affinity, Depth Strider, Frost Walker, Soul Speed
- Power, Punch, Flame, Infinity, Lure, Luck of the Sea
- Curse of Binding and Curse of Vanishing
- Enchanting table, anvil (partially done) and grindstone

### Blocks
- Containers and workstations (chests, furnaces, crafting table, ect)
- Beds, signs, scaffolding, honey and slime blocks
- Sweet berries, cobwebs, powder snow and soul sand slowing you down
- Beacon and jukebox (partially done)

### Redstone
- Redstone wire, repeaters, comparators, torches, lamps and observers
- Rails, tripwire and tripwire hooks, trapped chests, target block and lightning rod
- Redstone ore lighting up
- Pistons and sticky pistons (partially done)

### Commands
- clear, daylock, deop, effect, enchant, gamemode, gamerule, give, help, kick, list, op, plugins, save, say, setworldspawn, spawnpoint, stop, summon, teleport, tell, tickingarea, time, title, toggledownfall, version, weather and xp
- tellraw, titleraw, me and msg (partially done)

### Server
- Login, Xbox auth, NetherNet and resource packs
- Packet batching

### Plugins
- Plugins load from .phar files
- Events, plugin commands, scheduled tasks, config files and permissions

# What needs implementing:
### World
- [ ] Modern overworld generator
- [ ] Structures: dungeons, mineshafts, villages, strongholds, temples and huts, ocean monuments, shipwrecks, ruined portals, pillager outposts, woodland mansions, ancient cities, trail ruins, trial chambers
- [ ] Fossils, geodes ect
- [ ] Structure saving and /locate
- [ ] Same seed gives the same world as Bedrock
- [ ] World border
- [ ] Vanilla world format (LevelDB)

### Dimensions
- [ ] The Nether
- [ ] The End

### Mobs
- [ ] Mobs avoiding and running away, flying mobs, mobs opening doors
- [ ] Mobs picking up items and wearing armor
- [ ] Riding, leashes, taming and sitting
- [ ] Passive mobs: rabbit, horses, donkey, mule, llama, camel, goat, frog, axolotl, turtle, armadillo, sniffer, fox, panda, polar bear, wolf, cat, ocelot, parrot, bee, strider, bat, allay, happy ghast, copper golem, squid, dolphin, fish, nautilus
- [ ] Hostile mobs: magma cube, phantom, guardians, zombie villager, illagers and ravager, blaze, ghast, piglins, hoglin, zoglin, zombified piglin, wither skeleton, shulker, warden, breeze, creaking, parched, camel husk, zombie nautilus
- [ ] Iron golem and snow golem
- [ ] Bosses: ender dragon and wither, boss bars
- [ ] Villagers: jobs, trading, schedules, breeding, raids, wandering trader
- [ ] Piglin bartering
- [ ] Trial spawners and vaults, patrols, structure spawns

### Entities
- [ ] Fireballs and wind charges
- [ ] Fireworks
- [ ] Finishing minecarts and boats
- [ ] Armor stands and item frames
- [ ] End crystals

### Items
- [ ] Finishing the crossbow and trident, shield and mace
- [ ] Elytra and totem of undying
- [ ] Maps, compass, recovery compass, lodestone and clock
- [ ] Books
- [ ] Name tags, leads, saddles and horse armor
- [ ] Brush, goat horn, spyglass, music discs and bundles

### Player
- [ ] Offhand
- [ ] Swimming and crawling
- [ ] Hardcore mode
- [ ] Scoreboards and sidebar
- [ ] Achievements (the old Java style ones since Xbox ones can't be given by a third party server)
- [ ] Respawn anchor

### Enchantments
- [ ] Swift Sneak
- [ ] Crossbow, trident and mace enchantments
- [ ] Anvil getting damaged when used

### Blocks
- [ ] Note blocks, conduit, composter, bell, cake and candles
- [ ] Beehives and bee nests
- [ ] Chiseled bookshelf and decorated pot
- [ ] Suspicious sand and gravel
- [ ] Sculk blocks
- [ ] Portals
- [ ] Turtle eggs, sniffer eggs and frogspawn
- [ ] Command blocks and structure blocks

### Redstone
- [ ] Finishing pistons and sticky pistons
- [ ] Comparator readings for every block
- [ ] Moving the redstone engine to Rust

### Loot
- [ ] Loot tables from Mojang's data (mob drops are hand written for now)
- [ ] Checking every block's drops against Bedrock
- [ ] Trade tables from Mojang's data

### Commands
- [ ] difficulty, kill, damage, replaceitem, loot
- [ ] setblock, fill, clone
- [ ] execute, function, schedule, tag
- [ ] testfor, testforblock, testforblocks, scoreboard
- [ ] particle, playsound, stopsound, music, playanimation, camerashake, camera, fog, hud
- [ ] locate, spreadplayers, ride, mobevent, structure, place
- [ ] allowlist, permission, setmaxplayers, transfer, reload, changesetting
- [ ] Every target selector argument

### Server
- [ ] Allowlist, permissions file and bans
- [ ] Behavior packs and add-ons
- [ ] Script API (@minecraft/server)
- [ ] Player data in the vanilla format
- [ ] Chunk cache, query protocol
- [ ] Performance and stability

### Plugins
- [ ] More events
- [ ] Forms API
- [ ] Boss bar and scoreboard API
- [ ] Custom items and blocks
- [ ] More player API

# License:
RaspberryX is licensed under the [GNU Lesser General Public License v3.0](LICENSE), the LGPL lets plugins that only use RaspberryX's API pick their own license.