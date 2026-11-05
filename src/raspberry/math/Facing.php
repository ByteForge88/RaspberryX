<?php

declare(strict_types=1);

namespace raspberry\math;

use InvalidArgumentException;

use function array_search;
use function fmod;
use function round;

class Facing{

    public const DOWN = 0;
    public const UP = 1;
    public const NORTH = 2;
    public const SOUTH = 3;
    public const WEST = 4;
    public const EAST = 5;

    public const HORIZONTAL = [self::NORTH, self::SOUTH, self::WEST, self::EAST];

    public const ALL = [self::DOWN, self::UP, self::NORTH, self::SOUTH, self::WEST, self::EAST];

    private const NAMES = ["down", "up", "north", "south", "west", "east"];

    private const OFFSETS = [[0, -1, 0], [0, 1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]];

    /** Bedrock's old horizontal "direction" numbers: south 0, west 1, north 2, east 3 */
    private const LEGACY_DIRECTIONS = [self::SOUTH, self::WEST, self::NORTH, self::EAST];

    private function __construct(){
    }

    public static function opposite(int $face) : int{
        return $face ^ 1;
    }

    public static function isHorizontal(int $face) : bool{
        return $face >= self::NORTH;
    }

    public static function name(int $face) : string{
        return self::NAMES[$face];
    }

    /**
     * The face called "down", "up", "north"...
     */
    public static function fromName(string $name) : int{
        $face = array_search($name, self::NAMES, true);

        return $face === false ? throw new InvalidArgumentException("Unknown face {$name}") : $face;
    }

    /**
     * "y" for up and down, "z" for north and south, "x" for west and east.
     */
    public static function axis(int $face) : string{
        return match($face){
            self::DOWN, self::UP => "y",
            self::NORTH, self::SOUTH => "z",
            default => "x"
        };
    }

    /**
     * @return array{int, int, int}
     */
    public static function offset(int $face) : array{
        return self::OFFSETS[$face];
    }

    /**
     * The next horizontal face clockwise seen from above: north, east, south, west.
     */
    public static function rotateRight(int $face) : int{
        return match($face){
            self::NORTH => self::EAST,
            self::EAST => self::SOUTH,
            self::SOUTH => self::WEST,
            self::WEST => self::NORTH,
            default => throw new InvalidArgumentException("Face {$face} is not horizontal")
        };
    }

    public static function rotateLeft(int $face) : int{
        return self::opposite(self::rotateRight($face));
    }

    /**
     * The horizontal direction a player with this yaw looks towards.
     */
    public static function fromYaw(float $yaw) : int{
        $yaw = self::normalizeYaw($yaw);

        return match(true){
            $yaw > 45 && $yaw <= 135 => self::WEST,
            $yaw > -45 && $yaw <= 45 => self::SOUTH,
            $yaw > -135 && $yaw <= -45 => self::EAST,
            default => self::NORTH
        };
    }

    /**
     * The yaw as one of 16 steps (0 south, 4 west, 8 north, 12 east), like sign rotations.
     */
    public static function rotationFromYaw(float $yaw) : int{
        $step = (int) round(self::normalizeYaw($yaw) / 22.5);

        return ($step % 16 + 16) % 16;
    }

    public static function toLegacyDirection(int $face) : int{
        return match($face){
            self::SOUTH => 0,
            self::WEST => 1,
            self::NORTH => 2,
            self::EAST => 3,
            default => throw new InvalidArgumentException("Face {$face} is not horizontal")
        };
    }

    public static function fromLegacyDirection(int $direction) : int{
        return self::LEGACY_DIRECTIONS[$direction & 3];
    }

    /**
     * The yaw between -180 (exclusive) and 180.
     */
    private static function normalizeYaw(float $yaw) : float{
        $yaw = fmod($yaw, 360.0);

        if($yaw > 180){
            $yaw -= 360;
        }elseif($yaw <= -180){
            $yaw += 360;
        }

        return $yaw;
    }
}