<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\Server;

use raspberry\math\Facing;

use raspberry\world\Chunk;
use raspberry\world\Dimension;
use raspberry\world\World;

use JsonException;

use function abs;
use function array_pop;
use function max;
use function min;
use function preg_match;

class NetherPortals{

    public const string AXIS = "portal_axis";

    public const string AXIS_X = "x";
    public const string AXIS_Z = "z";

    public const int MIN_WIDTH = 2;
    public const int MIN_HEIGHT = 3;
    public const int MAX_SIZE = 21;

    public const int WIDTH = 0;
    public const int HEIGHT = 1;
    public const int PORTALS = 2;

    private const array EMPTY = [BlockIds::AIR => true, BlockIds::FIRE => true, BlockIds::SOUL_FIRE => true, BlockIds::PORTAL => true];

    private const string REPLACEABLE = '/^minecraft:(air|fire|soul_fire|short_grass|short_dry_grass|tall_grass|tall_dry_grass|fern|large_fern|deadbush|bush|vine|glow_lichen|sculk_vein|snow_layer|structure_void|crimson_roots|warped_roots|nether_sprouts|hanging_roots|leaf_litter)$/D';

    private const string LIQUID = '/^minecraft:(flowing_)?(water|lava)$/D';

    private const int CREATE_RADIUS = 16;

    private const int FALLBACK_MIN_Y = 70;

    private const int FALLBACK_TOP_MARGIN = 9;

    private static bool $breaking = false;

    private function __construct(){
    }

    public static function canLightIn(World $world) : bool{
        return $world->getDimension() !== Dimension::THE_END;
    }

    /**
     * @return array{int, int}
     */
    private static function widthStep(string $axis) : array{
        return $axis === self::AXIS_Z ? [0, 1] : [-1, 0];
    }

    private static function name(World $world, int $x, int $y, int $z) : ?string{
        if($y < $world->getMinY() || $y > $world->getMaxY() || !$world->isChunkLoaded($x >> 4, $z >> 4)){
            return null;
        }

        return $world->getBlock($x, $y, $z)->getName();
    }

    private static function isEmpty(World $world, int $x, int $y, int $z) : bool{
        return isset(self::EMPTY[self::name($world, $x, $y, $z) ?? ""]);
    }

    private static function isFrame(World $world, int $x, int $y, int $z) : bool{
        return self::name($world, $x, $y, $z) === BlockIds::OBSIDIAN;
    }

    private static function distanceToFrame(World $world, int $x, int $y, int $z, int $dx, int $dz) : int{
        for($i = 0; $i <= self::MAX_SIZE; ++$i){
            [$px, $pz] = [$x + $dx * $i, $z + $dz * $i];

            if(!self::isEmpty($world, $px, $y, $pz)){
                return self::isFrame($world, $px, $y, $pz) ? $i : 0;
            }

            if(!self::isFrame($world, $px, $y - 1, $pz)){
                return 0;
            }
        }

        return 0;
    }

    /**
     * The frame around the spot along $axis: its bottom corner inside (the east end along x,
     * the north end along z), how wide and tall the inside is and how many portal blocks fill it.
     *
     * @return array{array{int, int, int}, array{int, int, int}}|null
     */
    public static function findShape(World $world, int $x, int $y, int $z, string $axis) : ?array{
        [$dx, $dz] = self::widthStep($axis);
        $bottom = max($world->getMinY(), $y - self::MAX_SIZE);

        while($y > $bottom && self::isEmpty($world, $x, $y - 1, $z)){
            --$y;
        }

        $edge = self::distanceToFrame($world, $x, $y, $z, -$dx, -$dz) - 1;

        if($edge < 0){
            return null;
        }

        [$x, $z] = [$x - $dx * $edge, $z - $dz * $edge];
        $width = self::distanceToFrame($world, $x, $y, $z, $dx, $dz);

        if($width < self::MIN_WIDTH || $width > self::MAX_SIZE){
            return null;
        }

        $height = self::MAX_SIZE;
        $portals = 0;

        for($row = 0; $row < self::MAX_SIZE; ++$row){
            if(!self::isFrame($world, $x - $dx, $y + $row, $z - $dz) || !self::isFrame($world, $x + $dx * $width, $y + $row, $z + $dz * $width)){
                $height = $row;
                break;
            }

            for($column = 0; $column < $width; ++$column){
                $name = self::name($world, $x + $dx * $column, $y + $row, $z + $dz * $column);

                if(!isset(self::EMPTY[$name ?? ""])){
                    $height = $row;
                    break 2;
                }

                if($name === BlockIds::PORTAL){
                    ++$portals;
                }
            }
        }

        if($height < self::MIN_HEIGHT){
            return null;
        }

        for($column = 0; $column < $width; ++$column){
            if(!self::isFrame($world, $x + $dx * $column, $y + $height, $z + $dz * $column)){
                return null;
            }
        }

        return [[$x, $y, $z], [$width, $height, $portals]];
    }

    /**
     * Whether fire at the spot would light a portal, so it can go there even where fire could
     * not stay.
     */
    public static function wouldLight(World $world, int $x, int $y, int $z) : bool{
        if(!self::canLightIn($world)){
            return false;
        }

        foreach([self::AXIS_X, self::AXIS_Z] as $axis){
            $shape = self::findShape($world, $x, $y, $z, $axis);

            if($shape !== null && $shape[1][self::PORTALS] === 0){
                return true;
            }
        }

        return false;
    }

    /**
     * Fire was placed at the spot: fills the frame around it with portal blocks instead when
     * there is an empty one, along x first like Java.
     *
     * @throws JsonException
     */
    public static function light(World $world, int $x, int $y, int $z) : bool{
        if(!self::canLightIn($world)){
            return false;
        }

        foreach([self::AXIS_X, self::AXIS_Z] as $axis){
            $shape = self::findShape($world, $x, $y, $z, $axis);

            if($shape !== null && $shape[1][self::PORTALS] === 0){
                self::fill($world, $shape[0], $shape[1][self::WIDTH], $shape[1][self::HEIGHT], $axis);
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{int, int, int} $corner
     * @throws JsonException
     */
    private static function fill(World $world, array $corner, int $width, int $height, string $axis) : void{
        [$x, $y, $z] = $corner;
        [$dx, $dz] = self::widthStep($axis);
        $portal = Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::PORTAL)->withProperty(self::AXIS, $axis);

        for($row = 0; $row < $height; ++$row){
            for($column = 0; $column < $width; ++$column){
                $world->setBlock($x + $dx * $column, $y + $row, $z + $dz * $column, $portal);
            }
        }
    }

    public static function getAxis(BlockState $portal) : string{
        return $portal->getProperty(self::AXIS) === self::AXIS_Z ? self::AXIS_Z : self::AXIS_X;
    }

    /**
     * A block next to portal blocks changed: the whole portal goes out when its frame is no longer
     * whole. Only the blocks beside it in its own plane count, like Java, and those are always
     * obsidian or portal blocks.
     *
     * @throws JsonException
     */
    public static function onBlockChanged(World $world, int $x, int $y, int $z, BlockState $old, BlockState $new) : void{
        $was = $old->getName();

        if(self::$breaking || $new->getName() === BlockIds::PORTAL || ($was !== BlockIds::OBSIDIAN && $was !== BlockIds::PORTAL)){
            return;
        }

        foreach(Facing::ALL as $face){
            [$dx, $dy, $dz] = Facing::offset($face);
            [$nx, $ny, $nz] = [$x + $dx, $y + $dy, $z + $dz];

            if(self::name($world, $nx, $ny, $nz) !== BlockIds::PORTAL){
                continue;
            }

            $axis = self::getAxis($world->getBlock($nx, $ny, $nz));

            if($dy === 0 && ($axis === self::AXIS_X ? $dz !== 0 : $dx !== 0)){
                continue;
            }

            $shape = self::findShape($world, $nx, $ny, $nz, $axis);

            if($shape === null || $shape[1][self::PORTALS] !== $shape[1][self::WIDTH] * $shape[1][self::HEIGHT]){
                self::breakPortal($world, $nx, $ny, $nz, $axis);
            }
        }
    }

    /**
     * @throws JsonException
     */
    private static function breakPortal(World $world, int $x, int $y, int $z, string $axis) : void{
        [$dx, $dz] = self::widthStep($axis);
        $air = Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::AIR);
        $queue = [[$x, $y, $z]];
        $seen = [];
        self::$breaking = true;

        try{
            while(($position = array_pop($queue)) !== null){
                [$px, $py, $pz] = $position;
                $key = "$px:$py:$pz";

                if(isset($seen[$key]) || self::name($world, $px, $py, $pz) !== BlockIds::PORTAL){
                    continue;
                }

                $seen[$key] = true;
                $world->setBlock($px, $py, $pz, $air);

                foreach([[$dx, 0, $dz], [-$dx, 0, -$dz], [0, 1, 0], [0, -1, 0]] as [$sx, $sy, $sz]){
                    $queue[] = [$px + $sx, $py + $sy, $pz + $sz];
                }
            }
        }finally{
            self::$breaking = false;
        }
    }

    /**
     * The whole portal a portal block is part of: its bottom corner (as findShape gives it),
     * width and height. Null when the block is not in a whole portal.
     *
     * @return array{array{int, int, int}, array{int, int, int}}|null
     */
    public static function getPortalAt(World $world, int $x, int $y, int $z) : ?array{
        if(self::name($world, $x, $y, $z) !== BlockIds::PORTAL){
            return null;
        }

        $axis = self::getAxis($world->getBlock($x, $y, $z));
        $shape = self::findShape($world, $x, $y, $z, $axis);

        return $shape !== null && $shape[1][self::PORTALS] === $shape[1][self::WIDTH] * $shape[1][self::HEIGHT] ? $shape : null;
    }

    private static function surface(World $world, Chunk $chunk, int $x, int $z, int $top) : int{
        $highest = $chunk->getHighestNonEmptySubChunk();

        if($highest === null){
            return $world->getMinY();
        }

        for($y = min($top, ($highest << 4) + 15); $y >= $world->getMinY(); --$y){
            $block = $chunk->getBlock($x & 15, $y, $z & 15);

            if(BlockCollision::isSolid($block) || preg_match(self::LIQUID, $block->getName()) === 1){
                return min($top, $y + 1);
            }
        }

        return $world->getMinY();
    }

    private static function canReplace(World $world, int $x, int $y, int $z) : bool{
        $name = self::name($world, $x, $y, $z);

        return $name !== null && preg_match(self::REPLACEABLE, $name) === 1;
    }

    private static function isSolid(World $world, int $x, int $y, int $z) : bool{
        $name = self::name($world, $x, $y, $z);

        return $name !== null && BlockCollision::isSolid($world->getBlock($x, $y, $z));
    }

    /**
     * Whether a 2x3 portal with its frame fits at $x $y $z facing along ($sx, $sz): ground under
     * the four frame columns, room for the frame and the portal. $offset moves the check sideways
     * to make sure there is room to step out.
     */
    private static function canHostFrame(World $world, int $x, int $y, int $z, int $sx, int $sz, int $offset) : bool{
        [$cx, $cz] = [-$sz, $sx];

        for($along = -1; $along < 3; ++$along){
            for($up = -1; $up < 4; ++$up){
                [$px, $pz] = [$x + $sx * $along + $cx * $offset, $z + $sz * $along + $cz * $offset];

                if($up < 0 ? !self::isSolid($world, $px, $y + $up, $pz) : !self::canReplace($world, $px, $y + $up, $pz)){
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Builds a 2x3 portal for something coming through, like Java's PortalForcer: the nearest
     * spot within 16 blocks with ground and room around it, else any spot with room, else one
     * at y 70 (or as low as the dimension lets it) on a small obsidian platform. The chunks
     * within 16 blocks have to be loaded.
     *
     * @return array{array{int, int, int}, array{int, int, int}}|null
     * @throws JsonException
     */
    public static function create(World $world, int $targetX, int $targetY, int $targetZ, string $axis) : ?array{
        [$sx, $sz] = $axis === self::AXIS_Z ? [0, 1] : [1, 0];
        $top = $world->getMaxY();
        $best = null;
        $bestDistance = -1;
        $other = null;
        $otherDistance = -1;

        for($ring = 0; $ring <= self::CREATE_RADIUS; ++$ring){
            for($ox = -$ring; $ox <= $ring; ++$ox){
                for($oz = -$ring; $oz <= $ring; ++$oz){
                    if(abs($ox) !== $ring && abs($oz) !== $ring){
                        continue;
                    }

                    [$x, $z] = [$targetX + $ox - $sx, $targetZ + $oz - $sz];
                    $chunk = $world->getChunk($x >> 4, $z >> 4);

                    if($chunk === null || !$world->isChunkLoaded(($x + $sx) >> 4, ($z + $sz) >> 4)){
                        continue;
                    }

                    for($y = self::surface($world, $chunk, $x, $z, $top); $y >= $world->getMinY(); --$y){
                        if(!self::canReplace($world, $x, $y, $z)){
                            continue;
                        }

                        $runTop = $y;

                        while($y > $world->getMinY() && self::canReplace($world, $x, $y - 1, $z)){
                            --$y;
                        }

                        $depth = $runTop - $y;

                        if($y + 4 > $top || ($depth > 0 && $depth < 3) || !self::canHostFrame($world, $x, $y, $z, $sx, $sz, 0)){
                            continue;
                        }

                        $distance = ($x - $targetX) ** 2 + ($y - $targetY) ** 2 + ($z - $targetZ) ** 2;

                        if(self::canHostFrame($world, $x, $y, $z, $sx, $sz, -1) && self::canHostFrame($world, $x, $y, $z, $sx, $sz, 1) && ($bestDistance === -1 || $bestDistance > $distance)){
                            $bestDistance = $distance;
                            $best = [$x, $y, $z];
                        }

                        if($bestDistance === -1 && ($otherDistance === -1 || $otherDistance > $distance)){
                            $otherDistance = $distance;
                            $other = [$x, $y, $z];
                        }
                    }
                }
            }
        }

        $registry = Server::getInstance()->getBlockStateRegistry();
        $obsidian = $registry->getDefaultState(BlockIds::OBSIDIAN);
        $best ??= $other;

        if($best === null){
            $low = max($world->getMinY() + 1, self::FALLBACK_MIN_Y);
            $high = $top - self::FALLBACK_TOP_MARGIN;

            if($high < $low){
                return null;
            }

            $best = [$targetX - $sx, max($low, min($high, $targetY)), $targetZ - $sz];
            $air = $registry->getDefaultState(BlockIds::AIR);
            [$cx, $cz] = [-$sz, $sx];

            for($side = -1; $side < 2; ++$side){
                for($along = 0; $along < 2; ++$along){
                    for($up = -1; $up < 3; ++$up){
                        $world->setBlock($best[0] + $along * $sx + $side * $cx, $best[1] + $up, $best[2] + $along * $sz + $side * $cz, $up < 0 ? $obsidian : $air);
                    }
                }
            }
        }

        [$x, $y, $z] = $best;

        for($along = -1; $along < 3; ++$along){
            for($up = -1; $up < 4; ++$up){
                if($along === -1 || $along === 2 || $up === -1 || $up === 3){
                    $world->setBlock($x + $along * $sx, $y + $up, $z + $along * $sz, $obsidian);
                }
            }
        }

        $portal = $registry->getDefaultState(BlockIds::PORTAL)->withProperty(self::AXIS, $axis);

        for($along = 0; $along < 2; ++$along){
            for($up = 0; $up < 3; ++$up){
                $world->setBlock($x + $along * $sx, $y + $up, $z + $along * $sz, $portal);
            }
        }

        return self::getPortalAt($world, $x, $y, $z);
    }
}