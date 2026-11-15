<?php

declare(strict_types=1);

namespace raspberry\block\growth;

use raspberry\Server;

use raspberry\block\BlockIds;
use raspberry\block\BlockState;
use raspberry\block\BlockStateIds;

use raspberry\math\Vector3;

use raspberry\native\Native;

use raspberry\world\World;
use raspberry\world\generator\NativeBlocks;

use raspberry\event\block\StructureGrowEvent;

use FFI;

use function count;
use function mt_rand;
use function strlen;
use function unpack;

/**
 * Saplings growing into trees. The native side works out the tree from the generators' own
 * shapes and says which blocks change, so a tree that does not fit changes nothing.
 */
class Trees{

    private const OAK = 0;
    private const BIG_OAK = 1;
    private const BIRCH = 2;
    private const SPRUCE = 3;
    private const MEGA_SPRUCE = 4;
    private const MEGA_PINE = 5;
    private const JUNGLE = 6;
    private const MEGA_JUNGLE = 7;
    private const ACACIA = 8;
    private const DARK_OAK = 9;
    private const CHERRY = 10;
    private const MANGROVE = 11;
    private const PALE_OAK = 12;

    /** ground other than dirt and grass trees grow on */
    private const SOIL = [
        "minecraft:mud",
        "minecraft:moss_block",
        "minecraft:muddy_mangrove_roots",
        "minecraft:dirt_with_roots",
        "minecraft:pale_moss_block",
        "minecraft:clay"
    ];

    /** how far around the sapling chunks are handed over, a tree is never wider */
    private const CHUNK_REACH = 2;

    private function __construct(){
    }

    public static function grow(World $world, int $x, int $y, int $z, BlockState $sapling) : bool{
        $name = $sapling->getName();
        $square = self::findSquare($world, $x, $y, $z, $name);

        $kind = match($name){
            "minecraft:oak_sapling" => mt_rand(0, 9) === 0 ? self::BIG_OAK : self::OAK,
            "minecraft:birch_sapling" => self::BIRCH,
            "minecraft:spruce_sapling" => $square === null ? self::SPRUCE : (mt_rand(0, 1) === 0 ? self::MEGA_SPRUCE : self::MEGA_PINE),
            "minecraft:jungle_sapling" => $square === null ? self::JUNGLE : self::MEGA_JUNGLE,
            "minecraft:acacia_sapling" => self::ACACIA,
            "minecraft:dark_oak_sapling" => $square === null ? null : self::DARK_OAK,
            "minecraft:pale_oak_sapling" => $square === null ? null : self::PALE_OAK,
            "minecraft:cherry_sapling" => self::CHERRY,
            "minecraft:mangrove_propagule" => self::MANGROVE,
            default => null
        };

        if($kind === null){
            return false;
        }

        $big = $kind === self::MEGA_SPRUCE || $kind === self::MEGA_PINE || $kind === self::MEGA_JUNGLE || $kind === self::DARK_OAK || $kind === self::PALE_OAK;
        [$originX, $originZ] = $big ? $square : [$x, $z];
        $cleared = $big ? [[$originX, $y, $originZ], [$originX + 1, $y, $originZ], [$originX, $y, $originZ + 1], [$originX + 1, $y, $originZ + 1]] : [[$x, $y, $z]];

        $changes = self::growNative($world, $kind, $originX, $y, $originZ, $cleared);

        if($changes === null){
            return false;
        }

        $air = Server::getInstance()->getBlockStateRegistry()->getDefaultState(BlockIds::AIR);
        $changed = [];

        foreach($changes as [$bx, $by, $bz]){
            $changed[$bx . ":" . $by . ":" . $bz] = true;
        }

        foreach($cleared as [$bx, $by, $bz]){
            if(!isset($changed[$bx . ":" . $by . ":" . $bz])){
                $changes[] = [$bx, $by, $bz, $air];
            }
        }

        $event = new StructureGrowEvent($world, new Vector3($x, $y, $z), $sapling, $changes);
        $event->call();

        if($event->isCancelled()){
            return false;
        }

        foreach($event->getBlocks() as [$bx, $by, $bz, $state]){
            $world->setBlock($bx, $by, $bz, $state);
        }

        return true;
    }

    /**
     * @return array{int, int}|null
     */
    private static function findSquare(World $world, int $x, int $y, int $z, string $name) : ?array{
        foreach([[0, 0], [-1, 0], [0, -1], [-1, -1]] as [$dx, $dz]){
            [$cx, $cz] = [$x + $dx, $z + $dz];

            if(Nearby::name($world, $cx, $y, $cz) === $name && Nearby::name($world, $cx + 1, $y, $cz) === $name && Nearby::name($world, $cx, $y, $cz + 1) === $name && Nearby::name($world, $cx + 1, $y, $cz + 1) === $name){
                return [$cx, $cz];
            }
        }

        return null;
    }

    /**
     * @param list<array{int, int, int}> $cleared
     * @return list<array{int, int, int, BlockState}>|null
     */
    private static function growNative(World $world, int $kind, int $x, int $y, int $z, array $cleared) : ?array{
        $ffi = Native::get();
        $registry = Server::getInstance()->getBlockStateRegistry();
        $handles = [];

        for($cx = ($x >> 4) - self::CHUNK_REACH; $cx <= ($x >> 4) + self::CHUNK_REACH; ++$cx){
            for($cz = ($z >> 4) - self::CHUNK_REACH; $cz <= ($z >> 4) + self::CHUNK_REACH; ++$cz){
                $chunk = $world->getChunk($cx, $cz);

                if($chunk !== null){
                    $handles[] = [$cx, $cz, $chunk->getHandle()];
                }
            }
        }

        $chunkCount = count($handles);
        $chunks = $ffi->new("uint64_t[{$chunkCount}]");
        $positions = $ffi->new("int32_t[" . ($chunkCount * 2) . "]");

        foreach($handles as $i => [$cx, $cz, $handle]){
            $chunks[$i] = $handle;
            $positions[$i * 2] = $cx;
            $positions[$i * 2 + 1] = $cz;
        }

        $clearedCount = count($cleared);
        $spots = $ffi->new("int32_t[" . ($clearedCount * 3) . "]");

        foreach($cleared as $i => [$bx, $by, $bz]){
            $spots[$i * 3] = $bx;
            $spots[$i * 3 + 1] = $by;
            $spots[$i * 3 + 2] = $bz;
        }

        $soilCount = count(self::SOIL);
        $soil = $ffi->new("uint32_t[{$soilCount}]");

        foreach(self::SOIL as $i => $name){
            $soil[$i] = $registry->isRegistered($name) ? BlockStateIds::get($registry->getDefaultState($name)) : 0;
        }

        [$states, $stateCount] = NativeBlocks::get($registry);
        $out = $ffi->new("char*");
        $length = $ffi->new("size_t");

        $grown = $ffi->rx_tree_grow($kind, $x, $y, $z, mt_rand(), $chunks, $positions, $chunkCount, $spots, $clearedCount, $states, $stateCount, $soil, $soilCount, FFI::addr($out), FFI::addr($length));

        if($grown !== 1){
            return null;
        }

        $data = Native::take($out, $length->cdata);
        $changes = [];

        for($offset = 0, $size = strlen($data); $offset < $size; $offset += 16){
            $change = unpack("lx/ly/lz/Vstate", $data, $offset);
            $changes[] = [$change["x"], $change["y"], $change["z"], BlockStateIds::fromId($change["state"])];
        }

        return $changes;
    }
}