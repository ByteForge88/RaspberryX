<?php

declare(strict_types=1);

namespace raspberry\world;

use raspberry\block\Fire;
use raspberry\block\BlockState;
use raspberry\block\FallingBlocks;

use function max;

class BlockTicker{

    private World $world;

    /** @var array<int, array<string, array{int, int, int}>> */
    private array $due = [];

    /** @var array<string, int> */
    private array $scheduled = [];

    public function __construct(World $world){
        $this->world = $world;
    }

    public function schedule(int $x, int $y, int $z, int $delay) : void{
        $key = $x . ":" . $y . ":" . $z;
        $tick = $this->world->getCurrentTick() + max(1, $delay);

        if(isset($this->scheduled[$key]) && $this->scheduled[$key] <= $tick){
            return;
        }

        if(isset($this->scheduled[$key])){
            unset($this->due[$this->scheduled[$key]][$key]);
        }

        $this->scheduled[$key] = $tick;
        $this->due[$tick][$key] = [$x, $y, $z];
    }

    public function tick(int $currentTick) : void{
        foreach($this->due as $tick => $blocks){
            if($tick > $currentTick){
                continue;
            }

            unset($this->due[$tick]);

            foreach($blocks as $key => [$x, $y, $z]){
                unset($this->scheduled[$key]);

                if(!$this->world->isChunkLoaded($x >> 4, $z >> 4)){
                    continue;
                }

                $block = $this->world->getBlock($x, $y, $z);

                if(FallingBlocks::falls($block)){
                    FallingBlocks::tick($this->world, $x, $y, $z, $block);
                }elseif(Fire::isFire($block)){
                    Fire::tick($this->world, $x, $y, $z, $block);
                }
            }
        }
    }

    public function onBlockChanged(int $x, int $y, int $z, BlockState $old, BlockState $new) : void{
        if(FallingBlocks::falls($new)){
            $this->schedule($x, $y, $z, FallingBlocks::DELAY);
        }elseif(Fire::isFire($new) && !Fire::isFire($old)){
            $this->schedule($x, $y, $z, Fire::delay());
        }

        if(!FallingBlocks::canFallThrough($new) || FallingBlocks::canFallThrough($old)){
            return;
        }

        $world = $this->world;

        if($y < Chunk::MAX_Y && FallingBlocks::falls($world->getBlock($x, $y + 1, $z))){
            $this->schedule($x, $y + 1, $z, FallingBlocks::DELAY);
        }

        foreach([[0, 1, 0], [0, -1, 0], [1, 0, 0], [-1, 0, 0], [0, 0, 1], [0, 0, -1]] as [$dx, $dy, $dz]){
            [$nx, $ny, $nz] = [$x + $dx, $y + $dy, $z + $dz];

            if($ny >= Chunk::MIN_Y && $ny <= Chunk::MAX_Y && $world->isChunkLoaded($nx >> 4, $nz >> 4) && Fire::isFire($world->getBlock($nx, $ny, $nz))){
                $this->schedule($nx, $ny, $nz, 1);
            }
        }
    }
}