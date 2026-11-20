<?php

declare(strict_types=1);

namespace raspberry\world;

use raspberry\Server;

use raspberry\block\BlockCollision;
use raspberry\block\BlockProperties;
use raspberry\block\BlockState;

use raspberry\entity\EntityFactory;
use raspberry\entity\EntityIds;
use raspberry\entity\Mob;
use raspberry\entity\animal\Animal;
use raspberry\entity\monster\Monster;

use raspberry\math\Vector3;

use raspberry\player\GameMode;
use raspberry\player\Player;

use raspberry\world\biome\BiomeTags;

use function ceil;
use function count;
use function floor;
use function intdiv;
use function max;
use function min;
use function mt_getrandmax;
use function mt_rand;
use function preg_match;
use function shuffle;

use const PHP_FLOAT_MAX;

class NaturalSpawner{

    public const int MONSTER = 0;
    public const int CREATURE = 1;

    private const array CAPS = [self::MONSTER => 70, self::CREATURE => 10];
    private const int CAP_CHUNKS = 289;

    private const int CREATURE_TICKS = 400;

    private const int MIN_DISTANCE = 24;

    private const int DESPAWN_DISTANCE = 128;

    private const int CHUNK_RANGE = 128;

    private const int MAX_CLUSTER = 4;

    private const array SPAWNS = [
        self::MONSTER => [
            [EntityIds::SPIDER, 100, 4, 4],
            [EntityIds::ZOMBIE, 95, 4, 4],
            [EntityIds::ZOMBIE_VILLAGER_V2, 5, 1, 1],
            [EntityIds::SKELETON, 100, 4, 4],
            [EntityIds::CREEPER, 100, 4, 4],
            [EntityIds::SLIME, 100, 4, 4],
            [EntityIds::ENDERMAN, 10, 1, 4],
            [EntityIds::WITCH, 5, 1, 1]
        ],
        self::CREATURE => [
            [EntityIds::SHEEP, 12, 4, 4],
            [EntityIds::PIG, 10, 4, 4],
            [EntityIds::CHICKEN, 10, 4, 4],
            [EntityIds::COW, 8, 4, 4]
        ]
    ];

    private const array SIZES = [
        EntityIds::SPIDER => [1.4, 0.9],
        EntityIds::ZOMBIE => [0.6, 1.95],
        EntityIds::ZOMBIE_VILLAGER_V2 => [0.6, 1.95],
        EntityIds::SKELETON => [0.6, 1.99],
        EntityIds::CREEPER => [0.6, 1.7],
        EntityIds::SLIME => [2.04, 2.04],
        EntityIds::ENDERMAN => [0.6, 2.9],
        EntityIds::WITCH => [0.6, 1.95],
        EntityIds::CAVE_SPIDER => [0.7, 0.5],
        EntityIds::SHEEP => [0.9, 1.3],
        EntityIds::PIG => [0.9, 0.9],
        EntityIds::CHICKEN => [0.4, 0.7],
        EntityIds::COW => [0.9, 1.4]
    ];

    private const string NO_SPAWN_ON = '/^minecraft:(bedrock|barrier|\w*glass|\w*leaves(_flowered)?|\w*ice|magma|glowstone|sea_lantern|shroomlight|\w+_froglight|lit_pumpkin|lit_redstone_lamp|beacon)$/D';

    private const string NO_SPAWN_IN = '/^minecraft:(water|flowing_water|lava|flowing_lava|bubble_column|\w*rail|redstone_wire|\w*redstone_torch|lever|\w+_button|\w+_pressure_plate|tripwire_hook|' .
    'fire|soul_fire|sweet_berry_bush|wither_rose|cactus|powder_snow|magma)$/D';

    private const string LIQUID = '/^minecraft:(flowing_)?(water|lava)$|^minecraft:bubble_column$/D';

    private function __construct(){
    }

    public static function tick(World $world) : void{
        if(!$world->getGameRules()->getBool(GameRules::DO_MOB_SPAWNING)){
            return;
        }

        $players = self::getPlayers($world);

        if($players === []){
            return;
        }

        $chunks = [];

        foreach($world->getTickingChunks() as $chunk){
            $centerX = $chunk->getX() * 16 + 8;
            $centerZ = $chunk->getZ() * 16 + 8;

            foreach($players as $player){
                $position = $player->getPosition();

                if(($position->x - $centerX) ** 2 + ($position->z - $centerZ) ** 2 < self::CHUNK_RANGE ** 2){
                    $chunks[] = $chunk;
                    break;
                }
            }
        }

        if($chunks === []){
            return;
        }

        $categories = [];

        if(Server::getInstance()->getDifficulty() !== Difficulty::PEACEFUL){
            $categories[] = self::MONSTER;
        }

        if($world->getGameTime() % self::CREATURE_TICKS === 0){
            $categories[] = self::CREATURE;
        }

        if($categories === []){
            return;
        }

        $counts = self::countMobs($world);
        $caps = [];

        foreach($categories as $category){
            $caps[$category] = intdiv(self::CAPS[$category] * count($chunks), self::CAP_CHUNKS);
        }

        shuffle($chunks);

        foreach($chunks as $chunk){
            foreach($categories as $category){
                if($counts[$category] < $caps[$category]){
                    $counts[$category] += self::spawnInChunk($world, $chunk, $category, $players);
                }
            }
        }
    }

    /**
     * @return list<Player>
     */
    private static function getPlayers(World $world) : array{
        $players = [];

        foreach($world->getPlayers() as $player){
            if($player->isSpawned() && $player->isAlive() && $player->getGameMode() !== GameMode::SPECTATOR){
                $players[] = $player;
            }
        }

        return $players;
    }

    /**
     * @return array<int, int>
     */
    private static function countMobs(World $world) : array{
        $counts = [self::MONSTER => 0, self::CREATURE => 0];

        foreach($world->getEntities() as $entity){
            if($entity->isClosed() || !$entity instanceof Mob || !$entity->isAlive()){
                continue;
            }

            if($entity instanceof Monster){
                ++$counts[self::MONSTER];
            }elseif($entity instanceof Animal){
                ++$counts[self::CREATURE];
            }
        }

        return $counts;
    }

    /**
     * @param list<Player> $players
     */
    private static function spawnInChunk(World $world, Chunk $chunk, int $category, array $players) : int{
        $x = $chunk->getX() * 16 + mt_rand(0, 15);
        $z = $chunk->getZ() * 16 + mt_rand(0, 15);
        $top = $world->getLightEngine()->getSkyBottom($x, $z);

        if($top === null){
            return 0;
        }

        $y = mt_rand(Chunk::MIN_Y, min(Chunk::MAX_Y, $top + 1));

        if($y <= Chunk::MIN_Y || BlockCollision::isFullCube($world->getBlock($x, $y, $z))){
            return 0;
        }

        $spawn = $world->getSpawnPosition();
        $spawned = 0;

        for($pack = 0; $pack < 3; ++$pack){
            $px = $x;
            $pz = $z;
            $entry = null;
            $tries = (int) ceil(mt_rand() / mt_getrandmax() * 4.0);

            for($try = 0; $try < $tries; ++$try){
                $px += mt_rand(0, 5) - mt_rand(0, 5);
                $pz += mt_rand(0, 5) - mt_rand(0, 5);
                $sx = $px + 0.5;
                $sz = $pz + 0.5;

                $distance = self::nearestDistance($players, $sx, $y, $sz);

                if($distance <= self::MIN_DISTANCE ** 2 || ($spawn->x - $sx) ** 2 + ($spawn->y - $y - 0.5) ** 2 + ($spawn->z - $sz) ** 2 < self::MIN_DISTANCE ** 2 || !$world->isChunkTicking($px >> 4, $pz >> 4)){
                    continue;
                }

                if($entry === null){
                    $entry = self::pick($world, $category, $px, $y, $pz);

                    if($entry === null){
                        break;
                    }

                    $tries = $entry[2] + mt_rand(0, $entry[3] - $entry[2]);
                }

                $type = $entry[0];

                if(!EntityFactory::isRegistered($type)){
                    break;
                }

                if(($category === self::MONSTER && $distance > self::DESPAWN_DISTANCE ** 2) || !self::canSpawnAt($world, $type, $category, $px, $y, $pz)){
                    continue;
                }

                $mob = EntityFactory::create($type, $world, new Vector3($sx, $y, $sz), mt_rand() / mt_getrandmax() * 360.0);

                if($mob === null){
                    break;
                }

                if($mob instanceof Monster){
                    $mob->setPersistent(false);
                }elseif($mob instanceof Animal && $spawned > 0 && mt_rand(0, 99) < 5){
                    $mob->setBaby(true);
                }

                $mob->onSpawnedNaturally();

                if(++$spawned >= self::MAX_CLUSTER){
                    return $spawned;
                }
            }
        }

        return $spawned;
    }

    /**
     * @param list<Player> $players
     */
    private static function nearestDistance(array $players, float $x, float $y, float $z) : float{
        $nearest = PHP_FLOAT_MAX;
        $spot = new Vector3($x, $y, $z);

        foreach($players as $player){
            $nearest = min($nearest, $player->getPosition()->distanceSquared($spot));
        }

        return $nearest;
    }

    /**
     * @return array{string, int, int, int}|null
     */
    private static function pick(World $world, int $category, int $x, int $y, int $z) : ?array{
        $biome = $world->getBiome($x, $y, $z);

        if(!$biome->hasTag($category === self::MONSTER ? BiomeTags::MONSTER : BiomeTags::ANIMAL)){
            return null;
        }

        $entries = self::SPAWNS[$category];
        $total = 0;

        foreach($entries as $entry){
            $total += $entry[1];
        }

        $roll = mt_rand(0, $total - 1);

        foreach($entries as $entry){
            if(($roll -= $entry[1]) < 0){
                return $entry;
            }
        }

        return null;
    }

    public static function canSpawnAt(World $world, string $type, int $category, int $x, int $y, int $z) : bool{
        if($y <= Chunk::MIN_Y || $y + 1 > Chunk::MAX_Y || !$world->isChunkLoaded($x >> 4, $z >> 4)){
            return false;
        }

        $below = $world->getBlock($x, $y - 1, $z);

        if(!self::canSpawnOn($below) || !self::canSpawnIn($world->getBlock($x, $y, $z)) || !self::canSpawnIn($world->getBlock($x, $y + 1, $z))){
            return false;
        }

        if($category === self::MONSTER){
            if(!self::isDarkEnough($world, $x, $y, $z)){
                return false;
            }
        }elseif($below->getName() !== "minecraft:grass_block" || max($world->getSkyLight($x, $y, $z), $world->getBlockLight($x, $y, $z)) <= 8){
            return false;
        }

        [$width, $height] = self::getSize($type);

        return self::hasRoom($world, $x + 0.5, $y, $z + 0.5, $width, $height);
    }

    public static function isDarkEnough(World $world, int $x, int $y, int $z) : bool{
        $sky = $world->getSkyLight($x, $y, $z);

        if($sky > mt_rand(0, 31) || $world->getBlockLight($x, $y, $z) > 0){
            return false;
        }

        $darken = $world->getWeather()->isThundering() ? 10 : $world->getSkyDarken();

        return max(0, $sky - $darken) <= mt_rand(0, 7);
    }

    public static function canSpawnOn(BlockState $block) : bool{
        $name = $block->getName();

        if(preg_match(self::NO_SPAWN_ON, $name) === 1){
            return false;
        }

        if(BlockCollision::isFullCube($block)){
            return true;
        }

        if($block->hasProperty(BlockProperties::MINECRAFT_VERTICAL_HALF)){
            return $block->getProperty(BlockProperties::MINECRAFT_VERTICAL_HALF) === "top";
        }

        return $block->hasProperty(BlockProperties::UPSIDE_DOWN_BIT) && $block->getProperty(BlockProperties::UPSIDE_DOWN_BIT) === true;
    }

    private static function canSpawnIn(BlockState $block) : bool{
        return !BlockCollision::isFullCube($block) && preg_match(self::NO_SPAWN_IN, $block->getName()) !== 1;
    }

    /**
     * @return array{float, float}
     */
    public static function getSize(string $type) : array{
        return self::SIZES[$type] ?? [0.6, 1.8];
    }

    public static function hasRoom(World $world, float $x, float $y, float $z, float $width, float $height) : bool{
        $half = $width / 2;

        for($bx = (int) floor($x - $half); $bx <= (int) floor($x + $half - 0.001); ++$bx){
            for($bz = (int) floor($z - $half); $bz <= (int) floor($z + $half - 0.001); ++$bz){
                for($by = (int) floor($y); $by <= (int) floor($y + $height - 0.001); ++$by){
                    if($by > Chunk::MAX_Y || !$world->isChunkLoaded($bx >> 4, $bz >> 4)){
                        return false;
                    }

                    $block = $world->getBlock($bx, $by, $bz);

                    if(BlockCollision::isSolid($block) || preg_match(self::LIQUID, $block->getName()) === 1){
                        return false;
                    }
                }
            }
        }

        return true;
    }
}