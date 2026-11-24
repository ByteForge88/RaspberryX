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
use raspberry\entity\monster\Slime;

use raspberry\math\Vector3;

use raspberry\player\GameMode;
use raspberry\player\Player;

use raspberry\world\spawn\SpawnCondition;
use raspberry\world\spawn\SpawnRules;

use JsonException;

use function count;
use function floor;
use function intdiv;
use function min;
use function mt_getrandmax;
use function mt_rand;
use function preg_match;
use function shuffle;

use const PHP_FLOAT_MAX;

class NaturalSpawner{

    public const int MONSTER = 0;
    public const int CREATURE = 1;

    private const array POPULATIONS = [self::MONSTER => SpawnRules::MONSTER, self::CREATURE => SpawnRules::ANIMAL];

    private const array CAPS = [self::MONSTER => 70, self::CREATURE => 10];
    private const int CAP_CHUNKS = 289;

    private const int CREATURE_TICKS = 400;

    private const int MIN_DISTANCE = 24;

    private const int DESPAWN_DISTANCE = 128;

    private const int CHUNK_RANGE = 128;

    private const int HERD_SPREAD = 5;

    private const int HERD_TRIES = 4;

    private const int SURFACE_SCAN = 64;

    private const int BABY_CHANCE = 5;

    private const string CHANGE_TO_SKELETON = "change_to_skeleton";

    private const int CHANGE_TO_SKELETON_CHANCE = 20;

    private const string NO_SPAWN_ON = '/^minecraft:(bedrock|barrier|\w*glass|\w*leaves(_flowered)?|\w*ice|magma|glowstone|sea_lantern|shroomlight|\w+_froglight|lit_pumpkin|lit_redstone_lamp|beacon)$/D';

    private const string NO_SPAWN_IN = '/^minecraft:(water|flowing_water|lava|flowing_lava|bubble_column|\w*rail|redstone_wire|\w*redstone_torch|lever|\w+_button|\w+_pressure_plate|tripwire_hook|' .
    'fire|soul_fire|sweet_berry_bush|wither_rose|cactus|powder_snow|magma)$/D';

    private const string LIQUID = '/^minecraft:(flowing_)?(water|lava)$|^minecraft:bubble_column$/D';

    private const string WATER = '/^minecraft:(flowing_)?water$|^minecraft:bubble_column$/D';

    private const string LAVA = '/^minecraft:(flowing_)?lava$/D';

    private const string LEAVES = '/^minecraft:\w*leaves(_flowered)?$/D';

    private function __construct(){
    }

    /**
     * @throws JsonException
     */
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

        [$counts, $density] = self::countMobs($world);
        $caps = [];

        foreach($categories as $category){
            $caps[$category] = intdiv(self::CAPS[$category] * count($chunks), self::CAP_CHUNKS);
        }

        shuffle($chunks);

        foreach($chunks as $chunk){
            foreach($categories as $category){
                if($counts[$category] < $caps[$category]){
                    $counts[$category] += self::spawnInChunk($world, $chunk, $category, $players, $density);
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
     * @return array{array<int, int>, array<string, int>}
     * @throws JsonException
     */
    private static function countMobs(World $world) : array{
        $counts = [self::MONSTER => 0, self::CREATURE => 0];
        $density = [];

        foreach($world->getEntities() as $entity){
            if($entity->isClosed() || !$entity instanceof Mob || !$entity->isAlive()){
                continue;
            }

            if($entity instanceof Monster){
                ++$counts[self::MONSTER];
            }elseif($entity instanceof Animal){
                ++$counts[self::CREATURE];
            }

            $type = $entity->getNetworkTypeId();

            if(!SpawnRules::hasDensityLimit($type)){
                continue;
            }

            $position = $entity->getPosition();
            $key = $type . "|" . self::getColumn($world, $position->getFloorX(), $position->getFloorY(), $position->getFloorZ());
            $density[$key] = ($density[$key] ?? 0) + 1;
        }

        return [$counts, $density];
    }

    /**
     * @param list<Player> $players
     * @param array<string, int> $density
     * @throws JsonException
     */
    private static function spawnInChunk(World $world, Chunk $chunk, int $category, array $players, array &$density) : int{
        $x = $chunk->getX() * 16 + mt_rand(0, 15);
        $z = $chunk->getZ() * 16 + mt_rand(0, 15);
        $top = $world->getLightEngine()->getSkyBottom($x, $z);

        if($top === null){
            return 0;
        }

        $surface = self::getSurface($world, $x, $z, $top);
        $spawned = self::spawnHerd($world, $category, SpawnCondition::SURFACE, $x, $surface, $z, $players, $density);

        if($surface > Chunk::MIN_Y + 1){
            $spawned += self::spawnHerd($world, $category, SpawnCondition::UNDERGROUND, $x, mt_rand(Chunk::MIN_Y + 1, $surface - 1), $z, $players, $density);
        }

        return $spawned;
    }

    private static function getSurface(World $world, int $x, int $z, int $top) : int{
        $bottom = $top - self::SURFACE_SCAN;

        for($y = $top; $y > Chunk::MIN_Y + 1 && $y > $bottom; --$y){
            $below = $world->getBlock($x, $y - 1, $z);
            $name = $below->getName();

            if(BlockCollision::isSolid($below) && preg_match(self::LEAVES, $name) !== 1){
                return $y;
            }
        }

        return $top;
    }

    private static function getColumn(World $world, int $x, int $y, int $z) : string{
        $top = $world->getLightEngine()->getSkyBottom($x, $z);
        return $top !== null && $y >= self::getSurface($world, $x, $z, $top) ? SpawnCondition::SURFACE : SpawnCondition::UNDERGROUND;
    }

    private static function getMedium(BlockState $block) : string{
        $name = $block->getName();

        return match(true){
            preg_match(self::WATER, $name) === 1 => SpawnCondition::UNDERWATER,
            preg_match(self::LAVA, $name) === 1 => SpawnCondition::LAVA,
            default => SpawnCondition::AIR
        };
    }

    /**
     * @param list<Player> $players
     * @param array<string, int> $density
     * @throws JsonException
     */
    private static function spawnHerd(World $world, int $category, string $place, int $x, int $y, int $z, array $players, array &$density) : int{
        if($y <= Chunk::MIN_Y || $y + 1 > Chunk::MAX_Y || BlockCollision::isFullCube($world->getBlock($x, $y, $z))){
            return 0;
        }

        $condition = self::pick($world, $category, $place, $x, $y, $z, $density);

        if($condition === null){
            return 0;
        }

        $herd = $condition->pickHerd();
        $size = mt_rand($herd["min"], $herd["max"]);
        $spawn = $world->getSpawnPosition();
        $spawned = 0;

        for($try = 0; $try < $size * self::HERD_TRIES && $spawned < $size; ++$try){
            [$px, $py, $pz] = [$x, $y, $z];

            if($try > 0){
                $px += mt_rand(0, self::HERD_SPREAD) - mt_rand(0, self::HERD_SPREAD);
                $pz += mt_rand(0, self::HERD_SPREAD) - mt_rand(0, self::HERD_SPREAD);

                if(!$world->isChunkTicking($px >> 4, $pz >> 4)){
                    continue;
                }

                if($place === SpawnCondition::SURFACE){
                    $top = $world->getLightEngine()->getSkyBottom($px, $pz);

                    if($top === null){
                        continue;
                    }

                    $py = self::getSurface($world, $px, $pz, $top);
                }
            }

            $sx = $px + 0.5;
            $sz = $pz + 0.5;
            $distance = self::nearestDistance($players, $sx, $py, $sz);

            if(!$condition->allowsDistance($distance, self::MIN_DISTANCE) || ($spawn->x - $sx) ** 2 + ($spawn->y - $py - 0.5) ** 2 + ($spawn->z - $sz) ** 2 < self::MIN_DISTANCE ** 2){
                continue;
            }

            [$type] = $condition->pickType();
            $type = self::applySpawnEvent($condition, $type, $place);

            if(!EntityFactory::isRegistered($type) || ($category === self::MONSTER && $distance > self::DESPAWN_DISTANCE ** 2) || !self::canSpawnAt($world, $condition, $type, $place, $px, $py, $pz)){
                continue;
            }

            $mob = EntityFactory::create($type, $world, new Vector3($sx, $py, $sz), mt_rand() / mt_getrandmax() * 360.0);

            if($mob === null){
                continue;
            }

            if($mob instanceof Monster){
                $mob->setPersistent(false);
            }elseif($mob instanceof Animal && mt_rand(0, 99) < self::BABY_CHANCE){
                $mob->setBaby(true);
            }

            self::applyHerdEvent($mob, $herd, $spawned);
            $mob->onSpawnedNaturally();

            $key = $type . "|" . $place;
            $density[$key] = ($density[$key] ?? 0) + 1;
            ++$spawned;
        }

        return $spawned;
    }

    /**
     * @param array<string, int> $density
     * @throws JsonException
     */
    private static function pick(World $world, int $category, string $place, int $x, int $y, int $z, array $density) : ?SpawnCondition{
        $biome = $world->getBiome($x, $y, $z);
        $below = $world->getBlock($x, $y - 1, $z);
        $medium = self::getMedium($world->getBlock($x, $y, $z));
        $difficulty = Server::getInstance()->getDifficulty();
        $candidates = [];
        $total = 0;

        foreach(SpawnRules::getPopulation(self::POPULATIONS[$category]) as $condition){
            if(!$condition->isSupported() || !$condition->allowsPlace($place, $medium) || !EntityFactory::isRegistered($condition->getType())){
                continue;
            }

            if(!$condition->allowsDifficulty($difficulty) || !$condition->allowsHeight($y) || !$condition->allowsBiome($biome, $y) || !$condition->allowsGround($below) || !self::passesBuiltInRules($world, $condition->getType(), $x, $y, $z, false)){
                continue;
            }

            $limit = $condition->getDensityLimit($place);

            if(($limit !== null && ($density[$condition->getType() . "|" . $place] ?? 0) >= $limit) || !$condition->allowsBrightness($world, $x, $y, $z) || !$condition->passesRarity()){
                continue;
            }

            $candidates[] = $condition;
            $total += $condition->getWeight();
        }

        if($total <= 0){
            return null;
        }

        $roll = mt_rand(0, $total - 1);

        foreach($candidates as $condition){
            if(($roll -= $condition->getWeight()) < 0){
                return $condition;
            }
        }

        return null;
    }

    private static function applySpawnEvent(SpawnCondition $condition, string $type, string $place) : string{
        if($condition->getEvent() === self::CHANGE_TO_SKELETON && ($place === SpawnCondition::UNDERGROUND || mt_rand(0, 99) < self::CHANGE_TO_SKELETON_CHANCE)){
            return EntityIds::SKELETON;
        }

        return $type;
    }

    /**
     * @param array{min: int, max: int, event?: string, skip?: int, initial_event?: string, initial_count?: int} $herd
     */
    private static function applyHerdEvent(Mob $mob, array $herd, int $index) : void{
        $event = null;

        if(isset($herd["initial_event"]) && $index < ($herd["initial_count"] ?? 0)){
            $event = $herd["initial_event"];
        }elseif(isset($herd["event"]) && $index >= ($herd["skip"] ?? 0)){
            $event = $herd["event"];
        }

        if($event === SpawnCondition::ENTITY_BORN){
            $mob->setBaby(true);
        }
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
     * @throws JsonException
     */
    private static function canSpawnAt(World $world, SpawnCondition $condition, string $type, string $column, int $x, int $y, int $z) : bool{
        if($y <= Chunk::MIN_Y || $y + 1 > Chunk::MAX_Y || !$world->isChunkLoaded($x >> 4, $z >> 4) || !$condition->allowsHeight($y)){
            return false;
        }

        $below = $world->getBlock($x, $y - 1, $z);
        $at = $world->getBlock($x, $y, $z);
        $medium = self::getMedium($at);

        if(!$condition->allowsPlace($column, $medium) || !$condition->allowsGround($below) || !$condition->allowsBrightness($world, $x, $y, $z) || !self::passesBuiltInRules($world, $type, $x, $y, $z, true)){
            return false;
        }

        if($medium === SpawnCondition::AIR && (!self::canSpawnOn($below) || !self::canSpawnIn($at) || !self::canSpawnIn($world->getBlock($x, $y + 1, $z)))){
            return false;
        }

        [$width, $height] = self::getSize($type);
        return self::hasRoom($world, $x + 0.5, $y, $z + 0.5, $width, $height, $medium !== SpawnCondition::AIR);
    }

    private static function passesBuiltInRules(World $world, string $type, int $x, int $y, int $z, bool $roll) : bool{
        return match($type){
            EntityIds::SLIME => Slime::canSpawnNaturally($world, $x, $y, $z, $roll),
            default => true
        };
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
     * @throws JsonException
     */
    public static function getSize(string $type) : array{
        return SpawnRules::getSize($type);
    }

    public static function hasRoom(World $world, float $x, float $y, float $z, float $width, float $height, bool $inLiquid = false) : bool{
        $half = $width / 2;

        for($bx = (int) floor($x - $half); $bx <= (int) floor($x + $half - 0.001); ++$bx){
            for($bz = (int) floor($z - $half); $bz <= (int) floor($z + $half - 0.001); ++$bz){
                for($by = (int) floor($y); $by <= (int) floor($y + $height - 0.001); ++$by){
                    if($by > Chunk::MAX_Y || !$world->isChunkLoaded($bx >> 4, $bz >> 4)){
                        return false;
                    }

                    $block = $world->getBlock($bx, $by, $bz);

                    if(BlockCollision::isSolid($block) || (!$inLiquid && preg_match(self::LIQUID, $block->getName()) === 1)){
                        return false;
                    }
                }
            }
        }

        return true;
    }
}