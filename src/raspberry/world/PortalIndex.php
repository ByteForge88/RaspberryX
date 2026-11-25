<?php

declare(strict_types=1);

namespace raspberry\world;

use raspberry\block\BlockIds;
use raspberry\block\BlockState;

use raspberry\math\Vector3;

use raspberry\world\format\io\WorldMetadata;

use function abs;
use function array_map;
use function count;
use function is_int;
use function usort;

/**
 * Where a world's nether portal blocks are, so travelers find the portal on the other side
 * without loading the chunks around them first (Java keeps these as points of interest).
 */
class PortalIndex{

    private WorldMetadata $metadata;

    /** @var array<string, array<string, array{int, int, int}>> */
    private array $portals = [];

    public function __construct(WorldMetadata $metadata){
        $this->metadata = $metadata;

        foreach($metadata->portals as $position){
            if(count($position) === 3 && is_int($position[0]) && is_int($position[1]) && is_int($position[2])){
                $this->add($position[0], $position[1], $position[2]);
            }
        }
    }

    private function add(int $x, int $y, int $z) : void{
        $this->portals[($x >> 4) . ":" . ($z >> 4)]["$x:$y:$z"] = [$x, $y, $z];
    }

    /**
     * Forgets a portal block that is not there anymore.
     */
    public function remove(int $x, int $y, int $z) : void{
        $this->forget($x, $y, $z);
        $this->save();
    }

    private function forget(int $x, int $y, int $z) : void{
        $chunk = ($x >> 4) . ":" . ($z >> 4);
        unset($this->portals[$chunk]["$x:$y:$z"]);

        if(($this->portals[$chunk] ?? null) === []){
            unset($this->portals[$chunk]);
        }
    }

    public function onBlockChanged(int $x, int $y, int $z, BlockState $old, BlockState $new) : void{
        $was = $old->getName() === BlockIds::PORTAL;
        $is = $new->getName() === BlockIds::PORTAL;

        if($was === $is){
            return;
        }

        if($is){
            $this->add($x, $y, $z);
        }else{
            $this->forget($x, $y, $z);
        }

        $this->save();
    }

    private function save() : void{
        $list = [];

        foreach($this->portals as $blocks){
            foreach($blocks as $position){
                $list[] = $position;
            }
        }

        $this->metadata->portals = $list;
    }

    /**
     * @return list<array{int, int, int}>
     */
    public function findNear(Vector3 $target, int $radius) : array{
        [$tx, $ty, $tz] = [$target->getFloorX(), $target->getFloorY(), $target->getFloorZ()];
        $found = [];

        for($chunkX = ($tx - $radius) >> 4; $chunkX <= ($tx + $radius) >> 4; ++$chunkX){
            for($chunkZ = ($tz - $radius) >> 4; $chunkZ <= ($tz + $radius) >> 4; ++$chunkZ){
                foreach($this->portals["$chunkX:$chunkZ"] ?? [] as [$x, $y, $z]){
                    if(abs($x - $tx) <= $radius && abs($z - $tz) <= $radius){
                        $found[] = [($x - $tx) ** 2 + ($y - $ty) ** 2 + ($z - $tz) ** 2, $y, [$x, $y, $z]];
                    }
                }
            }
        }

        usort($found, static fn(array $a, array $b) : int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        return array_map(static fn(array $entry) : array => $entry[2], $found);
    }
}