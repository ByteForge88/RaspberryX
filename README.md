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
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-GPL--3.0-blue?style=flat-square" alt="License: GPL-3.0"></a>
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

# What's implemented:
### World
- Old, pre-normal, void and flat generators (biomes are climate based)
- Day and night cycle
- Weather: rain, snow and thunderstorms, snow layers and ice forming (lightning is partially done)
- Random block ticks, crops growing, saplings growing into trees, leaf decay and farmland
- Ice, snow and frosted ice melting, copper oxidizing
- Water and lava flowing and mixing into obsidian, cobblestone, stone and basalt (flowing is partially done)
- Fire spreading, explosions and TNT
- Falling blocks like sand and gravel
- Light engine (sky light and block light)
- Blocks breaking when what holds them up is gone, neighbor updates
- Ticking areas and simulation distance

### Mobs
- Mob engine written in Rust: movement, pathfinding, goals, melee attacks, despawning
- Pig, cow, chicken and sheep
- Zombie, husk, drowned, skeleton, stray, bogged, creeper, spider and cave spider (some still partially done)
- Animals spawn in new chunks, natural spawning with mob caps (partially done)
- Monster spawners and spawn eggs
- Mobs burn in the sun, spiders climb walls, skeletons shoot (partially done)

### Player
- Movement, attacking, armor, gamemodes, hunger, health, exp and levels, pvp, death and respawning
- Survival block breaking with break times checked by the server
- Food, potions (splash and lingering too) and chorus fruit
- Item drops and exp orbs (orbs fly to you and Mending repairs your stuff with them)
- Arrows, snowballs and eggs
- Fishing with Bedrock's loot tables (fish, treasure, junk)
- Sleeping in beds (partially done)
- Effects (90% done)

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
- [ ] Mobs avoiding and running away, swimming and flying mobs, mobs opening doors
- [ ] Mobs picking up items and wearing armor
- [ ] Riding, leashes, taming and sitting
- [ ] Spawn rules from Mojang's data
- [ ] Passive mobs: mooshroom, rabbit, horses, donkey, mule, llama, camel, goat, frog, axolotl, turtle, armadillo, sniffer, fox, panda, polar bear, wolf, cat, ocelot, parrot, bee, strider, bat, allay, happy ghast, copper golem, squid, dolphin, fish, nautilus
- [ ] Hostile mobs: enderman, endermite, silverfish, slime, magma cube, witch, phantom, guardians, zombie villager, illagers and ravager, blaze, ghast, piglins, hoglin, zoglin, zombified piglin, wither skeleton, shulker, warden, breeze, creaking
- [ ] Iron golem and snow golem
- [ ] Bosses: ender dragon and wither, boss bars
- [ ] Villagers: jobs, trading, schedules, breeding, raids, wandering trader
- [ ] Piglin bartering
- [ ] Trial spawners and vaults

### Entities
- [ ] Fireballs and wind charges
- [ ] Fireworks
- [ ] Minecarts and boats
- [ ] Armor stands, item frames and paintings
- [ ] End crystals

### Items
- [ ] Crossbow (partially done), trident, shield and mace
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
- [ ] Pistons and sticky pistons
- [ ] Rails
- [ ] Tripwire, trapped chests, target block and lightning rod
- [ ] Redstone ore lighting up
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