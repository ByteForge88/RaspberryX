<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class Attribute{

    public const HEALTH = "minecraft:health";
    public const ABSORPTION = "minecraft:absorption";
    public const MOVEMENT_SPEED = "minecraft:movement";
    public const HUNGER = "minecraft:player.hunger";
    public const SATURATION = "minecraft:player.saturation";
    public const EXHAUSTION = "minecraft:player.exhaustion";
    public const EXPERIENCE = "minecraft:player.experience";
    public const EXPERIENCE_LEVEL = "minecraft:player.level";

    public string $name;

    public float $min;

    public float $max;

    public float $value;

    public float $defaultMin;

    public float $defaultMax;

    public float $default;

    public function __construct(string $name, float $min, float $max, float $value, float $default){
        $this->name = $name;
        $this->min = $min;
        $this->max = $max;
        $this->value = $value;
        $this->defaultMin = $min;
        $this->defaultMax = $max;
        $this->default = $default;
    }

    public static function read(BinaryReader $reader) : self{
        $min = $reader->readFloat();
        $max = $reader->readFloat();
        $value = $reader->readFloat();
        $defaultMin = $reader->readFloat();
        $defaultMax = $reader->readFloat();
        $default = $reader->readFloat();
        $attribute = new self($reader->readString(), $min, $max, $value, $default);
        $attribute->defaultMin = $defaultMin;
        $attribute->defaultMax = $defaultMax;

        for($i = 0, $count = $reader->readUnsignedVarInt(); $i < $count; ++$i){
            $reader->readString();
            $reader->readString();
            $reader->readFloat();
            $reader->readInt();
            $reader->readInt();
            $reader->readBool();
        }

        return $attribute;
    }

    public function write(BinaryWriter $writer) : void{
        $writer->writeFloat($this->min);
        $writer->writeFloat($this->max);
        $writer->writeFloat($this->value);
        $writer->writeFloat($this->defaultMin);
        $writer->writeFloat($this->defaultMax);
        $writer->writeFloat($this->default);
        $writer->writeString($this->name);
        $writer->writeUnsignedVarInt(0); //modifiers
    }
}