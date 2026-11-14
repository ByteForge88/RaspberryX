<?php

declare(strict_types=1);

namespace raspberry\entity\object;

use raspberry\block\BlockCollision;

use raspberry\math\Vector3;

use raspberry\world\Chunk;

use function abs;
use function floor;
use function max;
use function min;

trait BlockPhysics{

    protected Vector3 $motion;

    protected bool $onGround = false;

    protected float $fallDistance = 0.0;

    public function getMotion() : Vector3{
        return $this->motion;
    }

    public function setMotion(Vector3 $motion) : void{
        $this->motion = $motion;
    }

    public function isOnGround() : bool{
        return $this->onGround;
    }

    protected function fall(float $gravity, float $drag, float $groundFriction) : void{
        $motionX = $this->motion->x;
        $motionY = $this->motion->y - $gravity;
        $motionZ = $this->motion->z;

        [$dx, $dy, $dz] = $this->collide($motionX, $motionY, $motionZ);

        $this->onGround = $motionY < 0 && $dy !== $motionY;
        $this->fallDistance = $this->onGround ? $this->fallDistance : ($dy < 0 ? $this->fallDistance - $dy : 0.0);

        $motionX = $dx !== $motionX ? 0.0 : $motionX * (1 - $drag);
        $motionY = $dy !== $motionY ? 0.0 : $motionY * (1 - $drag);
        $motionZ = $dz !== $motionZ ? 0.0 : $motionZ * (1 - $drag);

        if($this->onGround){
            $motionX *= $groundFriction;
            $motionZ *= $groundFriction;
        }

        $this->motion = new Vector3(abs($motionX) < 0.0001 ? 0.0 : $motionX, $motionY, abs($motionZ) < 0.0001 ? 0.0 : $motionZ);

        if($dx !== 0.0 || $dy !== 0.0 || $dz !== 0.0){
            $this->move(new Vector3($this->position->x + $dx, $this->position->y + $dy, $this->position->z + $dz), 0.0, 0.0);
        }
    }

    /**
     * @return array{float, float, float}
     */
    private function collide(float $dx, float $dy, float $dz) : array{
        $half = $this->getWidth() / 2;
        $box = [$this->position->x - $half, $this->position->y, $this->position->z - $half, $this->position->x + $half, $this->position->y + $this->getHeight(), $this->position->z + $half];
        $blocks = [];

        for($bx = (int) floor(min($box[0], $box[0] + $dx)); $bx <= (int) floor(max($box[3], $box[3] + $dx)); ++$bx){
            for($by = (int) floor(min($box[1], $box[1] + $dy)); $by <= (int) floor(max($box[4], $box[4] + $dy)); ++$by){
                for($bz = (int) floor(min($box[2], $box[2] + $dz)); $bz <= (int) floor(max($box[5], $box[5] + $dz)); ++$bz){
                    if($this->isSolidAt($bx, $by, $bz)){
                        $blocks[] = [$bx, $by, $bz, $bx + 1, $by + 1, $bz + 1];
                    }
                }
            }
        }

        foreach([1, 0, 2] as $axis){
            $delta = [$dx, $dy, $dz][$axis];

            if($delta === 0.0){
                continue;
            }

            foreach($blocks as $block){
                $delta = self::clip($box, $block, $axis, $delta);
            }

            $box[$axis] += $delta;
            $box[$axis + 3] += $delta;

            match($axis){
                0 => $dx = $delta,
                1 => $dy = $delta,
                2 => $dz = $delta
            };
        }

        return [$dx, $dy, $dz];
    }

    /**
     * @param array<int, float>     $box
     * @param array<int, int|float> $block
     */
    private static function clip(array $box, array $block, int $axis, float $delta) : float{
        foreach([0, 1, 2] as $other){
            if($other !== $axis && ($box[$other + 3] <= $block[$other] || $box[$other] >= $block[$other + 3])){
                return $delta;
            }
        }

        if($delta > 0 && $box[$axis + 3] <= $block[$axis]){
            return min($delta, $block[$axis] - $box[$axis + 3]);
        }

        if($delta < 0 && $box[$axis] >= $block[$axis + 3]){
            return max($delta, $block[$axis + 3] - $box[$axis]);
        }

        return $delta;
    }

    private function isSolidAt(int $x, int $y, int $z) : bool{
        if($y < Chunk::MIN_Y || $y > Chunk::MAX_Y || !$this->world->isChunkLoaded($x >> 4, $z >> 4)){
            return false;
        }

        return BlockCollision::isSolid($this->world->getBlock($x, $y, $z));
    }
}