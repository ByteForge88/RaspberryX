<?php

declare(strict_types=1);

namespace raspberry\player;

use raspberry\Server;

use raspberry\block\BlockIds;
use raspberry\block\NetherPortals;

use raspberry\math\Vector3;

use raspberry\world\Chunk;
use raspberry\world\Dimension;
use raspberry\world\World;

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

    public function __construct(Player $player){
        $this->player = $player;
    }

    /**
     * @throws JsonException
     */
    public function tick() : void{
        $player = $this->player;
        $portal = $player->getGameMode()->hasCollision() && !$player->getNetworkSession()->isChangingDimension() ? $this->findPortal() : null;

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
            $this->travel($portal, $destination, $target);
        }
    }

    /**
     * The first portal block the player's box touches.
     *
     * @return array{int, int, int}|null
     */
    private function findPortal() : ?array{
        $player = $this->player;
        $world = $player->getWorld();
        $position = $player->getPosition();
        $half = $player->getWidth() / 2;

        for($x = (int) floor($position->x - $half); $x <= (int) floor($position->x + $half); ++$x){
            for($z = (int) floor($position->z - $half); $z <= (int) floor($position->z + $half); ++$z){
                $chunk = $world->getChunk($x >> 4, $z >> 4);

                if($chunk === null){
                    continue;
                }

                for($y = max($world->getMinY(), (int) floor($position->y)); $y <= min($world->getMaxY(), (int) floor($position->y + $player->getHeight())); ++$y){
                    if($chunk->getBlock($x & 15, $y, $z & 15)->getName() === BlockIds::PORTAL){
                        return [$x, $y, $z];
                    }
                }
            }
        }

        return null;
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