<?php

declare(strict_types=1);

namespace raspberry\math;

use function floor;
use function sqrt;

/**
 * An immutable position or direction in the world.
 */
class Vector3{

    public float $x;
    public float $y;
    public float $z;

    public function __construct(float $x, float $y, float $z){
        $this->x = $x;
        $this->y = $y;
        $this->z = $z;
    }

    public static function zero() : self{
        return new self(0.0, 0.0, 0.0);
    }

    public function add(float $x, float $y, float $z) : self{
        return new self($this->x + $x, $this->y + $y, $this->z + $z);
    }

    public function getFloorX() : int{
        return (int) floor($this->x);
    }

    public function getFloorY() : int{
        return (int) floor($this->y);
    }

    public function getFloorZ() : int{
        return (int) floor($this->z);
    }

    public function getChunkX() : int{
        return $this->getFloorX() >> 4;
    }

    public function getChunkZ() : int{
        return $this->getFloorZ() >> 4;
    }

    public function distance(self $other) : float{
        return sqrt(($this->x - $other->x) ** 2 + ($this->y - $other->y) ** 2 + ($this->z - $other->z) ** 2);
    }

    public function equals(self $other) : bool{
        return $this->x === $other->x && $this->y === $other->y && $this->z === $other->z;
    }

    public function __toString() : string{
        return "({$this->x}, {$this->y}, {$this->z})";
    }
}