<?php

declare(strict_types=1);

namespace raspberry\block;

use raspberry\math\Facing;

use raspberry\world\Chunk;
use raspberry\world\World;

use function array_merge;
use function array_slice;
use function count;
use function min;

class PistonStructure{

    private World $world;

    private int $pistonX;
    private int $pistonY;
    private int $pistonZ;

    private int $pistonFacing;

    private int $pushDirection;

    private bool $extending;

    /** @var array{int, int, int} */
    private array $start;

    /** @var list<array{int, int, int}> */
    private array $toPush = [];

    /** @var list<array{int, int, int}> */
    private array $toDestroy = [];

    public function __construct(World $world, int $x, int $y, int $z, int $facing, bool $extending){
        $this->world = $world;
        $this->pistonX = $x;
        $this->pistonY = $y;
        $this->pistonZ = $z;
        $this->pistonFacing = $facing;
        $this->extending = $extending;
        $this->pushDirection = $extending ? $facing : Facing::opposite($facing);

        [$dx, $dy, $dz] = Facing::offset($facing);
        $distance = $extending ? 1 : 2;
        $this->start = [$x + $dx * $distance, $y + $dy * $distance, $z + $dz * $distance];
    }

    public function getPushDirection() : int{
        return $this->pushDirection;
    }

    /**
     * @return list<array{int, int, int}>
     */
    public function getToPush() : array{
        return $this->toPush;
    }

    /**
     * @return list<array{int, int, int}>
     */
    public function getToDestroy() : array{
        return $this->toDestroy;
    }

    public function resolve() : bool{
        $this->toPush = [];
        $this->toDestroy = [];

        [$x, $y, $z] = $this->start;
        $block = $this->at($x, $y, $z);

        if(!$this->isPushable($block, $x, $y, $z, false, $this->pistonFacing)){
            if($this->extending && $block !== null && Pistons::breaksWhenPushed($block)){
                $this->toDestroy[] = $this->start;
                return true;
            }

            return false;
        }

        if(!$this->addBlockLine($x, $y, $z, $this->pushDirection)){
            return false;
        }

        for($i = 0; $i < count($this->toPush); ++$i){
            [$px, $py, $pz] = $this->toPush[$i];
            $block = $this->at($px, $py, $pz);

            if($block !== null && Pistons::isSticky($block) && !$this->addBranchingBlocks($px, $py, $pz)){
                return false;
            }
        }

        return true;
    }

    private function addBlockLine(int $x, int $y, int $z, int $direction) : bool{
        $block = $this->at($x, $y, $z);

        if($block !== null && $block->getName() === BlockIds::AIR){
            return true;
        }

        if(!$this->isPushable($block, $x, $y, $z, false, $direction) || $this->isPiston($x, $y, $z) || $this->indexOf($x, $y, $z) !== -1){
            return true;
        }

        $count = 1;

        if($count + count($this->toPush) > Pistons::PUSH_LIMIT){
            return false;
        }

        [$bx, $by, $bz] = Facing::offset(Facing::opposite($this->pushDirection));

        while($block !== null && Pistons::isSticky($block)){
            [$px, $py, $pz] = [$x + $bx * $count, $y + $by * $count, $z + $bz * $count];
            $previous = $block;
            $block = $this->at($px, $py, $pz);

            if($block === null || $block->getName() === BlockIds::AIR || !Pistons::canStick($previous, $block) || !$this->isPushable($block, $px, $py, $pz, false, Facing::opposite($direction)) || $this->isPiston($px, $py, $pz)){
                break;
            }

            if(++$count + count($this->toPush) > Pistons::PUSH_LIMIT){
                return false;
            }
        }

        for($k = $count - 1; $k >= 0; --$k){
            $this->toPush[] = [$x + $bx * $k, $y + $by * $k, $z + $bz * $k];
        }

        [$fx, $fy, $fz] = Facing::offset($this->pushDirection);

        for($j = 1; ; ++$j){
            [$px, $py, $pz] = [$x + $fx * $j, $y + $fy * $j, $z + $fz * $j];
            $index = $this->indexOf($px, $py, $pz);

            if($index !== -1){
                $this->reorderAtCollision($count, $index);

                for($l = 0, $last = min($index + $count, count($this->toPush) - 1); $l <= $last; ++$l){
                    [$sx, $sy, $sz] = $this->toPush[$l];
                    $sticky = $this->at($sx, $sy, $sz);

                    if($sticky !== null && Pistons::isSticky($sticky) && !$this->addBranchingBlocks($sx, $sy, $sz)){
                        return false;
                    }
                }

                return true;
            }

            $block = $this->at($px, $py, $pz);

            if($block !== null && $block->getName() === BlockIds::AIR){
                return true;
            }

            if(!$this->isPushable($block, $px, $py, $pz, true, $direction) || $this->isPiston($px, $py, $pz)){
                return false;
            }

            if($block !== null && Pistons::breaksWhenPushed($block)){
                $this->toDestroy[] = [$px, $py, $pz];
                return true;
            }

            if(count($this->toPush) >= Pistons::PUSH_LIMIT){
                return false;
            }

            $this->toPush[] = [$px, $py, $pz];
            ++$count;
        }
    }

    private function reorderAtCollision(int $count, int $index) : void{
        $size = count($this->toPush);

        $this->toPush = array_merge(
            array_slice($this->toPush, 0, $index),
            array_slice($this->toPush, $size - $count),
            array_slice($this->toPush, $index, $size - $count - $index)
        );
    }

    private function addBranchingBlocks(int $x, int $y, int $z) : bool{
        $block = $this->at($x, $y, $z);
        $axis = Facing::axis($this->pushDirection);

        foreach(Facing::ALL as $face){
            if(Facing::axis($face) === $axis){
                continue;
            }

            [$dx, $dy, $dz] = Facing::offset($face);
            $other = $this->at($x + $dx, $y + $dy, $z + $dz);

            if($block !== null && $other !== null && Pistons::canStick($other, $block) && !$this->addBlockLine($x + $dx, $y + $dy, $z + $dz, $face)){
                return false;
            }
        }

        return true;
    }

    private function isPushable(?BlockState $block, int $x, int $y, int $z, bool $allowDestroy, int $facing) : bool{
        if($block === null){
            return false;
        }

        if($block->getName() === BlockIds::AIR){
            return true;
        }

        if(($this->pushDirection === Facing::DOWN && $y <= Chunk::MIN_Y) || ($this->pushDirection === Facing::UP && $y >= Chunk::MAX_Y)){
            return false;
        }

        return Pistons::canMove($this->world, $x, $y, $z, $block, $allowDestroy, $this->pushDirection === $facing);
    }

    private function isPiston(int $x, int $y, int $z) : bool{
        return $x === $this->pistonX && $y === $this->pistonY && $z === $this->pistonZ;
    }

    private function indexOf(int $x, int $y, int $z) : int{
        foreach($this->toPush as $index => [$px, $py, $pz]){
            if($px === $x && $py === $y && $pz === $z){
                return $index;
            }
        }

        return -1;
    }

    private function at(int $x, int $y, int $z) : ?BlockState{
        if($y < Chunk::MIN_Y || $y > Chunk::MAX_Y || !$this->world->isChunkLoaded($x >> 4, $z >> 4)){
            return null;
        }

        return $this->world->getBlock($x, $y, $z);
    }
}