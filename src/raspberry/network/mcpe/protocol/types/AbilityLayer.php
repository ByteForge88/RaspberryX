<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class AbilityLayer{

    public const TYPE_CUSTOM_CACHE = 0;
    public const TYPE_BASE = 1;
    public const TYPE_SPECTATOR = 2;
    public const TYPE_COMMANDS = 3;
    public const TYPE_EDITOR = 4;
    public const TYPE_LOADING_SCREEN = 5;

    public const BUILD = 1 << 0;
    public const MINE = 1 << 1;
    public const DOORS_AND_SWITCHES = 1 << 2;
    public const OPEN_CONTAINERS = 1 << 3;
    public const ATTACK_PLAYERS = 1 << 4;
    public const ATTACK_MOBS = 1 << 5;
    public const OPERATOR_COMMANDS = 1 << 6;
    public const TELEPORT = 1 << 7;
    public const INVULNERABLE = 1 << 8;
    public const FLYING = 1 << 9;
    public const MAY_FLY = 1 << 10;
    public const INSTANT_BUILD = 1 << 11;
    public const LIGHTNING = 1 << 12;
    public const FLY_SPEED = 1 << 13;
    public const WALK_SPEED = 1 << 14;
    public const MUTED = 1 << 15;
    public const WORLD_BUILDER = 1 << 16;
    public const NO_CLIP = 1 << 17;
    public const PRIVILEGED_BUILDER = 1 << 18;
    public const VERTICAL_FLY_SPEED = 1 << 19;

    /** every ability bit */
    public const ALL = (1 << 20) - 1;

    public const DEFAULT_FLY_SPEED = 0.05;
    public const DEFAULT_VERTICAL_FLY_SPEED = 1.0;
    public const DEFAULT_WALK_SPEED = 0.1;

    public int $type;

    public int $abilities;

    public int $values;

    public float $flySpeed;

    public float $verticalFlySpeed;

    public float $walkSpeed;

    public function __construct(int $type, int $abilities, int $values, float $flySpeed = self::DEFAULT_FLY_SPEED, float $verticalFlySpeed = self::DEFAULT_VERTICAL_FLY_SPEED, float $walkSpeed = self::DEFAULT_WALK_SPEED){
        $this->type = $type;
        $this->abilities = $abilities;
        $this->values = $values;
        $this->flySpeed = $flySpeed;
        $this->verticalFlySpeed = $verticalFlySpeed;
        $this->walkSpeed = $walkSpeed;
    }

    public static function read(BinaryReader $reader) : self{
        return new self($reader->readUnsignedShort(), $reader->readUnsignedInt(), $reader->readUnsignedInt(), $reader->readFloat(), $reader->readFloat(), $reader->readFloat());
    }

    public function write(BinaryWriter $writer) : void{
        $writer->writeUnsignedShort($this->type);
        $writer->writeUnsignedInt($this->abilities);
        $writer->writeUnsignedInt($this->values);
        $writer->writeFloat($this->flySpeed);
        $writer->writeFloat($this->verticalFlySpeed);
        $writer->writeFloat($this->walkSpeed);
    }
}