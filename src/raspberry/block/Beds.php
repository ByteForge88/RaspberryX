<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\entity\EntityIds;

use raspberry\math\Facing;
use raspberry\math\Vector3;

use raspberry\player\GameMode;
use raspberry\player\Player;

use raspberry\world\Chunk;
use raspberry\world\World;

use function abs;
use function in_array;

class Beds{

    private const int REACH = 3;
    private const int REACH_UP = 2;

    /** monsters this close to the bed keep survival players up */
    private const int MONSTER_RANGE = 8;
    private const int MONSTER_RANGE_UP = 5;

    /** the night, and how much longer it is when it rains */
    private const int NIGHT_START = 12542;
    private const int NIGHT_END = 23459;
    private const int RAIN_NIGHT_START = 12010;
    private const int RAIN_NIGHT_END = 23991;

    private const array MONSTERS = [
        EntityIds::BLAZE,
        EntityIds::BOGGED,
        EntityIds::BREEZE,
        EntityIds::CAVE_SPIDER,
        EntityIds::CREAKING,
        EntityIds::CREEPER,
        EntityIds::DROWNED,
        EntityIds::ELDER_GUARDIAN,
        EntityIds::ENDERMAN,
        EntityIds::ENDERMITE,
        EntityIds::EVOCATION_ILLAGER,
        EntityIds::GUARDIAN,
        EntityIds::HUSK,
        EntityIds::PARCHED,
        EntityIds::PIGLIN_BRUTE,
        EntityIds::PILLAGER,
        EntityIds::RAVAGER,
        EntityIds::SILVERFISH,
        EntityIds::SKELETON,
        EntityIds::SPIDER,
        EntityIds::STRAY,
        EntityIds::VEX,
        EntityIds::VINDICATOR,
        EntityIds::WARDEN,
        EntityIds::WITCH,
        EntityIds::WITHER,
        EntityIds::WITHER_SKELETON,
        EntityIds::ZOGLIN,
        EntityIds::ZOMBIE,
        EntityIds::ZOMBIE_VILLAGER
    ];

    private function __construct(){
    }

    /**
     * A player clicked a bed: sets their spawn point and lies them down when they can sleep.
     */
    public static function use(Player $player, int $x, int $y, int $z, BlockState $block) : bool{
        $world = $player->getWorld();
        $head = self::getHead($world, $x, $y, $z, $block);

        if($head === null){
            return true;
        }

        [$headX, $headY, $headZ] = $head;
        $facing = Facing::fromLegacyDirection((int) $block->getProperty(BlockProperties::DIRECTION));
        [$offsetX, , $offsetZ] = Facing::offset(Facing::opposite($facing));

        if(!self::isInReach($player, $headX, $headY, $headZ) && !self::isInReach($player, $headX + $offsetX, $headY, $headZ + $offsetZ)){
            $player->sendTranslation("%tile.bed.tooFar");
            return true;
        }

        if($headY < Chunk::MAX_Y && BlockCollision::isFullCube($world->getBlock($headX, $headY + 1, $headZ))){
            $player->sendTranslation("%tile.bed.obstructed");
            return true;
        }

        if(!$player->isSleeping() && self::getSleeper($world, $headX, $headY, $headZ) !== null){
            $player->sendTranslation("%tile.bed.occupied");
            return true;
        }

        $spawn = $player->getSpawnPosition();

        if(!$player->isSpawnAtBed() || $spawn === null || $spawn->getFloorX() !== $headX || $spawn->getFloorY() !== $headY || $spawn->getFloorZ() !== $headZ || $player->getSpawnLocation()[0] !== $world){
            $player->setSpawnPosition(new Vector3($headX, $headY, $headZ), $world, true);
            $player->sendTranslation("%tile.bed.respawnSet");
        }

        if($player->isSleeping()){
            return true;
        }

        if(!self::canSleepNow($world)){
            $player->sendTranslation("%tile.bed.noSleep");
            return true;
        }

        if($player->getGameMode() !== GameMode::CREATIVE && self::hasMonstersAround($world, $headX, $headY, $headZ)){
            $player->sendTranslation("%tile.bed.notSafe");
            return true;
        }

        $player->sleep(new Vector3($headX, $headY, $headZ));
        return true;
    }

    /**
     * Thunderstorms let players sleep at any time, rain a little earlier and later.
     */
    public static function canSleepNow(World $world) : bool{
        $weather = $world->getWeather();

        if($weather->isThundering()){
            return true;
        }

        $time = $world->getTimeOfDay();

        if($weather->isRaining()){
            return $time >= self::RAIN_NIGHT_START && $time <= self::RAIN_NIGHT_END;
        }

        return $time >= self::NIGHT_START && $time <= self::NIGHT_END;
    }

    /**
     * Where the head of the bed with a half at x y z is, null when the other half is missing.
     *
     * @return array{int, int, int}|null
     */
    public static function getHead(World $world, int $x, int $y, int $z, BlockState $block) : ?array{
        if($block->getName() !== BlockIds::BED){
            return null;
        }

        if($block->getProperty(BlockProperties::HEAD_PIECE_BIT) === true){
            return [$x, $y, $z];
        }

        [$offsetX, , $offsetZ] = Facing::offset(Facing::fromLegacyDirection((int) $block->getProperty(BlockProperties::DIRECTION)));
        $other = $world->getBlock($x + $offsetX, $y, $z + $offsetZ);

        if($other->getName() !== BlockIds::BED || $other->getProperty(BlockProperties::HEAD_PIECE_BIT) !== true){
            return null;
        }

        return [$x + $offsetX, $y, $z + $offsetZ];
    }

    /**
     * Whether the head of a whole bed is at x y z.
     */
    public static function isBedHead(World $world, int $x, int $y, int $z) : bool{
        $block = $world->getBlock($x, $y, $z);

        return $block->getName() === BlockIds::BED && self::getHead($world, $x, $y, $z, $block) === [$x, $y, $z] && self::getFoot($world, $x, $y, $z, $block) !== null;
    }

    /**
     * Shows the bed as taken or free to everyone, both halves.
     */
    public static function setOccupied(World $world, int $x, int $y, int $z, bool $occupied) : void{
        $head = $world->getBlock($x, $y, $z);

        if($head->getName() !== BlockIds::BED){
            return;
        }

        if($head->getProperty(BlockProperties::OCCUPIED_BIT) !== $occupied){
            $world->setBlock($x, $y, $z, $head->withProperty(BlockProperties::OCCUPIED_BIT, $occupied));
        }

        $foot = self::getFoot($world, $x, $y, $z, $head);

        if($foot !== null){
            [$footX, $footY, $footZ, $block] = $foot;

            if($block->getProperty(BlockProperties::OCCUPIED_BIT) !== $occupied){
                $world->setBlock($footX, $footY, $footZ, $block->withProperty(BlockProperties::OCCUPIED_BIT, $occupied));
            }
        }
    }

    /**
     * Who is sleeping in the bed with its head at x y z.
     */
    public static function getSleeper(World $world, int $x, int $y, int $z) : ?Player{
        foreach($world->getPlayers() as $player){
            $bed = $player->getSleepingPosition();

            if($bed !== null && $bed->getFloorX() === $x && $bed->getFloorY() === $y && $bed->getFloorZ() === $z){
                return $player;
            }
        }

        return null;
    }

    /**
     * Somewhere next to the bed with its head at x y z to respawn on, null when the bed is gone
     * or walled in.
     */
    public static function findStandUpSpot(World $world, int $x, int $y, int $z) : ?Vector3{
        if($y <= Chunk::MIN_Y || $y >= Chunk::MAX_Y - 1 || !self::isBedHead($world, $x, $y, $z)){
            return null;
        }

        $foot = self::getFoot($world, $x, $y, $z, $world->getBlock($x, $y, $z));

        if($foot === null){
            return null;
        }

        //around the foot first, the way vanilla gets players out of bed
        foreach([[$foot[0], $foot[2]], [$x, $z]] as [$halfX, $halfZ]){
            foreach([0, 1, -1] as $offsetY){
                for($offsetX = -1; $offsetX <= 1; ++$offsetX){
                    for($offsetZ = -1; $offsetZ <= 1; ++$offsetZ){
                        $standX = $halfX + $offsetX;
                        $standY = $y + $offsetY;
                        $standZ = $halfZ + $offsetZ;

                        if(self::canStandAt($world, $standX, $standY, $standZ)){
                            return new Vector3($standX + 0.5, (float) $standY, $standZ + 0.5);
                        }
                    }
                }
            }
        }

        return null;
    }

    private static function canStandAt(World $world, int $x, int $y, int $z) : bool{
        $below = $world->getBlock($x, $y - 1, $z);

        return $below->getName() !== BlockIds::BED && BlockCollision::isSolid($below)
            && !BlockCollision::isSolid($world->getBlock($x, $y, $z))
            && !BlockCollision::isSolid($world->getBlock($x, $y + 1, $z));
    }

    /**
     * @return array{int, int, int, BlockState}|null
     */
    private static function getFoot(World $world, int $x, int $y, int $z, BlockState $head) : ?array{
        [$offsetX, , $offsetZ] = Facing::offset(Facing::opposite(Facing::fromLegacyDirection((int) $head->getProperty(BlockProperties::DIRECTION))));
        $foot = $world->getBlock($x + $offsetX, $y, $z + $offsetZ);

        if($foot->getName() !== BlockIds::BED || $foot->getProperty(BlockProperties::HEAD_PIECE_BIT) === true){
            return null;
        }

        return [$x + $offsetX, $y, $z + $offsetZ, $foot];
    }

    private static function isInReach(Player $player, int $x, int $y, int $z) : bool{
        $position = $player->getPosition();

        return abs($position->x - ($x + 0.5)) <= self::REACH + 0.5
            && abs($position->z - ($z + 0.5)) <= self::REACH + 0.5
            && abs($position->y - $y) <= self::REACH_UP + 0.5;
    }

    private static function hasMonstersAround(World $world, int $x, int $y, int $z) : bool{
        foreach($world->getEntities() as $entity){
            if(!in_array($entity->getNetworkTypeId(), self::MONSTERS, true)){
                continue;
            }

            $position = $entity->getPosition();

            if(abs($position->x - ($x + 0.5)) <= self::MONSTER_RANGE && abs($position->z - ($z + 0.5)) <= self::MONSTER_RANGE && abs($position->y - $y) <= self::MONSTER_RANGE_UP){
                return true;
            }
        }

        return false;
    }
}