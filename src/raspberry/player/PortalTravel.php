<?php

declare(strict_types=1);

namespace raspberry\player;

use raspberry\Server;

use raspberry\block\Beds;
use raspberry\block\BlockIds;
use raspberry\block\NetherPortals;

use raspberry\math\Vector3;

use raspberry\network\mcpe\protocol\LevelSoundEventPacket;
use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use raspberry\world\Chunk;
use raspberry\world\Dimension;
use raspberry\world\World;
use raspberry\world\generator\EndGenerator;

use Closure;
use JsonException;

use function floor;
use function max;
use function min;

/**
 * Taking a player through a nether portal: four seconds standing in one (a tick in creative),
 * then the portal on the other side within 128 blocks (16 in the nether), or a new one.
 *
 * While the player waits the chunks around where they will come out are already being made,
 * so the loading screen on the other side is short.
 */
class PortalTravel{

    private const int SURVIVAL_WAIT = 80;

    private const int CREATIVE_WAIT = 1;

    /** ticks out of a portal before one works again, counted again while standing in one */
    private const int COOLDOWN = 10;

    private const int SEARCH_OVERWORLD = 128;

    private const int SEARCH_NETHER = 16;

    /** chunks around the exit made while the player waits, the 16 blocks a new portal can go plus its frame */
    private const int PREPARE_RADIUS = 2;

    private const int BORDER = 29999984;

    /** index entries looked at before giving up and building a portal */
    private const int MAX_CANDIDATES = 64;

    private Player $player;

    private int $ticksInPortal = 0;

    private int $cooldown = 0;

    private bool $travelling = false;
    private bool $inEndPortal = false;
    private int $endTravelId = 0;

    public function __construct(Player $player){
        $this->player = $player;
    }

    /**
     * @throws JsonException
     */
    public function tick() : void{
        $player = $this->player;
        $portal = $player->getGameMode()->hasCollision() && !$player->getNetworkSession()->isChangingDimension() ? $this->findPortal() : null;

        if($portal !== null && $portal[3] === BlockIds::END_PORTAL){
            $this->ticksInPortal = 0;

            if(!$this->inEndPortal && !$this->travelling){
                $this->inEndPortal = true;
                $world = $player->getWorld();

                if($world->getDimension() === Dimension::THE_END){
                    $this->leaveEnd($world);
                }else{
                    $this->enterEnd($world);
                }
            }

            return;
        }

        $this->inEndPortal = false;

        if($portal === null){
            $this->ticksInPortal = 0;

            if($this->cooldown > 0){
                --$this->cooldown;
            }

            return;
        }

        if($this->cooldown > 0){
            $this->cooldown = self::COOLDOWN;
            return;
        }

        if($this->travelling){
            return;
        }

        $world = $player->getWorld();
        $destination = $world->getDimensionWorld($world->getDimension() === Dimension::NETHER ? Dimension::OVERWORLD : Dimension::NETHER);
        $target = self::scale($player->getPosition(), $world, $destination);

        if(++$this->ticksInPortal === 1){
            self::prepare($destination, $target);
        }

        if($this->ticksInPortal >= ($player->getGameMode()->hasInstantBuild() ? self::CREATIVE_WAIT : self::SURVIVAL_WAIT)){
            $this->travel([$portal[0], $portal[1], $portal[2]], $destination, $target);
        }
    }

    /**
     * The end portal or first nether portal block the player's box touches.
     *
     * @return array{int, int, int, string}|null
     */
    private function findPortal() : ?array{
        $player = $this->player;
        $world = $player->getWorld();
        $position = $player->getPosition();
        $half = $player->getWidth() / 2;
        $top = $position->y + $player->getHeight();
        $canUseEndPortal = $player->getVehicle() === null;
        $nether = null;

        for($x = (int) floor($position->x - $half); $x <= (int) floor($position->x + $half); ++$x){
            for($z = (int) floor($position->z - $half); $z <= (int) floor($position->z + $half); ++$z){
                $chunk = $world->getChunk($x >> 4, $z >> 4);

                if($chunk === null){
                    continue;
                }

                for($y = max($world->getMinY(), (int) floor($position->y)); $y <= min($world->getMaxY(), (int) floor($top)); ++$y){
                    $name = $chunk->getBlock($x & 15, $y, $z & 15)->getName();

                    if($name === BlockIds::END_PORTAL && $canUseEndPortal && $position->x + $half > $x && $position->z + $half > $z && $top > $y){
                        return [$x, $y, $z, $name];
                    }

                    if($name === BlockIds::PORTAL){
                        $nether ??= [$x, $y, $z, $name];
                    }
                }
            }
        }

        return $nether;
    }

    /**
     * Waits for the chunks the obsidian platform crosses, then takes the player to the End.
     *
     * @throws JsonException
     */
    private function enterEnd(World $from) : void{
        $destination = $from->getDimensionWorld(Dimension::THE_END);
        [$x, $y, $z] = [EndGenerator::PLATFORM_X, EndGenerator::PLATFORM_Y, EndGenerator::PLATFORM_Z];
        $this->requestEndChunks($from, $destination, $x, $z, EndGenerator::PLATFORM_RADIUS, function() use ($destination, $x, $y, $z) : void{
            $this->arriveInEnd($destination, $x, $y, $z);
        });
    }

    private function leaveEnd(World $from) : void{
        $spawn = $this->player->getPersonalSpawnLocation();

        if($spawn === null || $spawn[0]->getDimension() === Dimension::THE_END){
            $this->returnToWorldSpawn($from);
            return;
        }

        [$destination, $target] = $spawn;
        [$x, $y, $z] = [$target->getFloorX(), $target->getFloorY(), $target->getFloorZ()];
        $bed = $this->player->isSpawnAtBed();

        if($y <= $destination->getMinY() || $y >= $destination->getMaxY()){
            $this->returnToWorldSpawn($from);
            return;
        }

        $this->requestEndChunks($from, $destination, $x, $z, $bed ? 2 : 0, function() use ($from, $destination, $target, $x, $y, $z, $bed) : void{
            $position = $bed ? Beds::findStandUpSpot($destination, $x, $y, $z) : $target;

            if($position === null){
                $this->returnToWorldSpawn($from);
                return;
            }

            $this->arriveFromEnd($destination, $position);
        });
    }

    private function returnToWorldSpawn(World $from) : void{
        $destination = $from->getOverworld();
        $target = $destination->getSpawnPosition();
        $this->requestEndChunks($from, $destination, $target->getFloorX(), $target->getFloorZ(), 0, function() use ($destination, $target) : void{
            $this->arriveFromEnd($destination, $target);
        });
    }

    private function arriveFromEnd(World $destination, Vector3 $target) : void{
        $player = $this->player;
        $player->teleport($target, null, null, $destination);

        if($player->getWorld() === $destination && $player->getPosition()->equals($target)){
            $player->getNetworkSession()->sendDataPacket(LevelSoundEventPacket::create(LevelSoundEvent::PORTAL_TRAVEL, $target->x, $target->y, $target->z));
        }
    }

    /**
     * @param Closure() : void $onReady
     */
    private function requestEndChunks(World $from, World $destination, int $x, int $z, int $radius, Closure $onReady) : void{
        [$minX, $maxX] = [($x - $radius) >> 4, ($x + $radius) >> 4];
        [$minZ, $maxZ] = [($z - $radius) >> 4, ($z + $radius) >> 4];
        $waiting = ($maxX - $minX + 1) * ($maxZ - $minZ + 1);
        $id = ++$this->endTravelId;

        for($cx = $minX; $cx <= $maxX; ++$cx){
            for($cz = $minZ; $cz <= $maxZ; ++$cz){
                $destination->requestChunk($cx, $cz, function(Chunk $chunk) use (&$waiting, $id, $from, $onReady) : void{
                    if(--$waiting !== 0 || $id !== $this->endTravelId){
                        return;
                    }

                    $player = $this->player;
                    if(!$player->isClosed() && !$player->isDead() && $player->isSpawned() && $player->getWorld() === $from &&
                        $player->getGameMode()->hasCollision() && $player->getVehicle() === null && !$player->getNetworkSession()->isChangingDimension() && !$this->travelling){
                        $onReady();
                    }
                });
            }
        }
    }

    /**
     * @throws JsonException
     */
    private function arriveInEnd(World $destination, int $x, int $y, int $z) : void{
        $player = $this->player;
        $session = $player->getNetworkSession();
        $target = new Vector3($x + 0.5, $y + 1, $z + 0.5);
        $player->teleport($target, 90.0, 0.0, $destination);

        if($player->getWorld() !== $destination || !$player->getPosition()->equals($target)){
            return;
        }

        $registry = Server::getInstance()->getBlockStateRegistry();
        $obsidian = $registry->getDefaultState(BlockIds::OBSIDIAN);
        $air = $registry->getDefaultState(BlockIds::AIR);
        $radius = EndGenerator::PLATFORM_RADIUS;

        for($dx = -$radius; $dx <= $radius; ++$dx){
            for($dz = -$radius; $dz <= $radius; ++$dz){
                for($dy = 0; $dy <= 3; ++$dy){
                    $block = $dy === 0 ? $obsidian : $air;

                    if(!$destination->getBlock($x + $dx, $y + $dy, $z + $dz)->equals($block)){
                        $destination->setBlock($x + $dx, $y + $dy, $z + $dz, $block);
                    }
                }
            }
        }

        $session->sendDataPacket(LevelSoundEventPacket::create(LevelSoundEvent::PORTAL_TRAVEL, $target->x, $target->y, $target->z));
    }

    /**
     * Where the player would come out before looking for a portal: x and z times 8 into the
     * overworld, divided by 8 into the nether, inside the world's edge and height.
     */
    private static function scale(Vector3 $position, World $from, World $to) : Vector3{
        $scale = $from->getDimension()->getCoordinateScale() / $to->getDimension()->getCoordinateScale();
        $limit = self::BORDER;

        return new Vector3(
            max(-$limit, min($limit, $position->x * $scale)),
            max($to->getMinY(), min($to->getMaxY(), $position->y)),
            max(-$limit, min($limit, $position->z * $scale))
        );
    }

    /**
     * Starts making the chunks around $target in the background.
     */
    private static function prepare(World $world, Vector3 $target) : void{
        $centerX = $target->getFloorX() >> 4;
        $centerZ = $target->getFloorZ() >> 4;

        for($x = $centerX - self::PREPARE_RADIUS; $x <= $centerX + self::PREPARE_RADIUS; ++$x){
            for($z = $centerZ - self::PREPARE_RADIUS; $z <= $centerZ + self::PREPARE_RADIUS; ++$z){
                $world->requestChunk($x, $z, static function(Chunk $chunk) : void{});
            }
        }
    }

    /**
     * Once the chunks around $target are there, finds or builds the portal and moves the player.
     *
     * @param array{int, int, int} $from
     * @throws JsonException
     */
    private function travel(array $from, World $destination, Vector3 $target) : void{
        $this->travelling = true;
        $centerX = $target->getFloorX() >> 4;
        $centerZ = $target->getFloorZ() >> 4;
        $waiting = (self::PREPARE_RADIUS * 2 + 1) ** 2;

        for($x = $centerX - self::PREPARE_RADIUS; $x <= $centerX + self::PREPARE_RADIUS; ++$x){
            for($z = $centerZ - self::PREPARE_RADIUS; $z <= $centerZ + self::PREPARE_RADIUS; ++$z){
                $destination->requestChunk($x, $z, function(Chunk $chunk) use (&$waiting, $from, $destination, $target) : void{
                    if(--$waiting === 0){
                        $this->arrive($from, $destination, $target);
                    }
                });
            }
        }
    }

    /**
     * @param array{int, int, int} $from
     * @throws JsonException
     */
    private function arrive(array $from, World $destination, Vector3 $target) : void{
        $player = $this->player;
        $this->travelling = false;
        $this->ticksInPortal = 0;

        if($player->isClosed() || !$player->isSpawned()){
            return;
        }

        $world = $player->getWorld();
        $axis = $world->getBlock($from[0], $from[1], $from[2])->getName() === BlockIds::PORTAL ? NetherPortals::getAxis($world->getBlock($from[0], $from[1], $from[2])) : NetherPortals::AXIS_X;
        $portal = $this->findExit($destination, $target) ?? NetherPortals::create($destination, $target->getFloorX(), $target->getFloorY(), $target->getFloorZ(), $axis);

        if($portal === null){
            Server::getInstance()->getLogger()->warning("Found no place for a nether portal near " . $target . " in " . $destination->getName());
            return;
        }

        $this->cooldown = self::COOLDOWN;
        $player->teleport(self::exitPosition($destination, $portal), null, null, $destination);
    }

    /**
     * The nearest whole portal near $target, dropping portal blocks from the index that are not
     * there anymore.
     *
     * @return array{array{int, int, int}, array{int, int, int}}|null
     */
    private function findExit(World $world, Vector3 $target) : ?array{
        $radius = $world->getDimension() === Dimension::NETHER ? self::SEARCH_NETHER : self::SEARCH_OVERWORLD;
        $tried = 0;

        foreach($world->getPortals()->findNear($target, $radius) as [$x, $y, $z]){
            $world->loadChunk($x >> 4, $z >> 4);
            $portal = NetherPortals::getPortalAt($world, $x, $y, $z);

            if($portal !== null){
                return $portal;
            }

            if($world->getBlock($x, $y, $z)->getName() !== BlockIds::PORTAL){
                $world->getPortals()->remove($x, $y, $z);
            }

            if(++$tried >= self::MAX_CANDIDATES){
                break;
            }
        }

        return null;
    }

    /**
     * @param array{array{int, int, int}, array{int, int, int}} $portal
     */
    private static function exitPosition(World $world, array $portal) : Vector3{
        [[$x, $y, $z], [$width]] = $portal;
        $axis = NetherPortals::getAxis($world->getBlock($x, $y, $z));
        $middle = ($width - 1) / 2;
        return $axis === NetherPortals::AXIS_Z ? new Vector3($x + 0.5, $y, $z + $middle + 0.5) : new Vector3($x - $middle + 0.5, $y, $z + 0.5);
    }
}