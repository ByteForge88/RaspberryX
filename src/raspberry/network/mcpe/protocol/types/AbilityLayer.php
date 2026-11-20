<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class AbilityLayer{

    public const int TYPE_CUSTOM_CACHE = 0;
    public const int TYPE_BASE = 1;
    public const int TYPE_SPECTATOR = 2;
    public const int TYPE_COMMANDS = 3;
    public const int TYPE_EDITOR = 4;
    public const int TYPE_LOADING_SCREEN = 5;

    public const int BUILD = 1 << 0;
    public const int MINE = 1 << 1;
    public const int DOORS_AND_SWITCHES = 1 << 2;
    public const int OPEN_CONTAINERS = 1 << 3;
    public const int ATTACK_PLAYERS = 1 << 4;
    public const int ATTACK_MOBS = 1 << 5;
    public const int OPERATOR_COMMANDS = 1 << 6;
    public const int TELEPORT = 1 << 7;
    public const int INVULNERABLE = 1 << 8;
    public const int FLYING = 1 << 9;
    public const int MAY_FLY = 1 << 10;
    public const int INSTANT_BUILD = 1 << 11;
    public const int LIGHTNING = 1 << 12;
    public const int FLY_SPEED = 1 << 13;
    public const int WALK_SPEED = 1 << 14;
    public const int MUTED = 1 << 15;
    public const int WORLD_BUILDER = 1 << 16;
    public const int NO_CLIP = 1 << 17;
    public const int PRIVILEGED_BUILDER = 1 << 18;
    public const int VERTICAL_FLY_SPEED = 1 << 19;

    /** every ability bit */
    public const int ALL = (1 << 20) - 1;

    public const float DEFAULT_FLY_SPEED = 0.05;
    public const float DEFAULT_VERTICAL_FLY_SPEED = 1.0;
    public const float DEFAULT_WALK_SPEED = 0.1;

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