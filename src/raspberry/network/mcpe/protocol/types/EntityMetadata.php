<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types;

use raspberry\network\encoding\BinaryWriter;

use function ksort;
use function count;

class EntityMetadata{

    public const KEY_FLAGS = 0;
    public const KEY_COLOR_INDEX = 3;
    public const KEY_NAME = 4;
    public const KEY_AIR_SUPPLY = 7;
    public const KEY_EFFECT_COLOR = 8;
    public const KEY_EFFECT_AMBIENCE = 9;
    public const KEY_PLAYER_FLAGS = 26;
    public const KEY_SCALE = 38;
    public const KEY_AIR_SUPPLY_MAX = 42;
    public const KEY_WIDTH = 53;
    public const KEY_HEIGHT = 54;
    public const KEY_ALWAYS_SHOW_NAME_TAG = 81;
    public const KEY_FLAGS_TWO = 92;

    public const TYPE_BYTE = 0;
    public const TYPE_SHORT = 1;
    public const TYPE_INT = 2;
    public const TYPE_FLOAT = 3;
    public const TYPE_STRING = 4;
    public const TYPE_LONG = 7;

    public const FLAG_ON_FIRE = 0;
    public const FLAG_SNEAKING = 1;
    public const FLAG_SPRINTING = 3;
    public const FLAG_SHOW_NAME = 14;
    public const FLAG_ALWAYS_SHOW_NAME = 15;
    public const FLAG_CAN_CLIMB = 19;
    public const FLAG_BREATHING = 35;
    public const FLAG_HAS_COLLISION = 48;
    public const FLAG_HAS_GRAVITY = 49;

    /** @var array<int, array{int, int|float|string}> key => [type, value] */
    private array $values = [];

    public function setByte(int $key, int $value) : void{
        $this->values[$key] = [self::TYPE_BYTE, $value];
    }

    public function setShort(int $key, int $value) : void{
        $this->values[$key] = [self::TYPE_SHORT, $value];
    }

    public function setInt(int $key, int $value) : void{
        $this->values[$key] = [self::TYPE_INT, $value];
    }

    public function setFloat(int $key, float $value) : void{
        $this->values[$key] = [self::TYPE_FLOAT, $value];
    }

    public function setString(int $key, string $value) : void{
        $this->values[$key] = [self::TYPE_STRING, $value];
    }

    public function setLong(int $key, int $value) : void{
        $this->values[$key] = [self::TYPE_LONG, $value];
    }

    /**
     * Sets or clears one bit of the FLAGS (flags 0-63) or FLAGS_TWO (64-127) long.
     */
    public function setFlag(int $flag, bool $value = true) : void{
        $key = $flag < 64 ? self::KEY_FLAGS : self::KEY_FLAGS_TWO;
        $bit = 1 << ($flag % 64);
        $current = $this->values[$key][1] ?? 0;
        $this->setLong($key, $value ? ($current | $bit) : ($current & ~$bit));
    }

    public function isEmpty() : bool{
        return $this->values === [];
    }

    /**
     * Entries must be in key order, otherwise some client features (e.g. NPC dialogs) break.
     */
    public function write(BinaryWriter $writer) : void{
        ksort($this->values);

        $writer->writeUnsignedVarInt(count($this->values));

        foreach($this->values as $key => [$type, $value]){
            $writer->writeUnsignedVarInt($key);
            $writer->writeUnsignedVarInt($type);
            $writer->writeByte($type); //legacy copy of the type

            match($type){
                self::TYPE_BYTE => $writer->writeByte($value & 0xff),
                self::TYPE_SHORT => $writer->writeShort($value),
                self::TYPE_INT => $writer->writeVarInt($value),
                self::TYPE_FLOAT => $writer->writeFloat($value),
                self::TYPE_STRING => $writer->writeString($value),
                self::TYPE_LONG => $writer->writeVarLong($value)
            };
        }
    }
}