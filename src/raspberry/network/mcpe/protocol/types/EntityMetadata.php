<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types;

use raspberry\network\encoding\BinaryWriter;

use function ksort;
use function count;

class EntityMetadata{

    public const int KEY_FLAGS = 0;
    public const int KEY_HEALTH = 1;
    public const int KEY_VARIANT = 2;
    public const int KEY_COLOR_INDEX = 3;
    public const int KEY_NAME = 4;
    public const int KEY_OWNER = 5;
    public const int KEY_TARGET = 6;
    public const int KEY_AIR_SUPPLY = 7;
    public const int KEY_EFFECT_COLOR = 8;
    public const int KEY_EFFECT_AMBIENCE = 9;
    public const int KEY_HURT_TIME = 11;
    public const int KEY_HURT_DIRECTION = 12;
    public const int KEY_PADDLE_TIME_LEFT = 13;
    public const int KEY_PADDLE_TIME_RIGHT = 14;
    public const int KEY_EXPERIENCE_VALUE = 15;
    public const int KEY_CUSTOM_DISPLAY = 18;
    public const int KEY_CARRY_BLOCK_STATE = 23;
    public const int KEY_PLAYER_FLAGS = 26;
    public const int KEY_PLAYER_BED_POSITION = 28;
    public const int KEY_AUX_VALUE_DATA = 36;
    public const int KEY_SCALE = 38;
    public const int KEY_AIR_SUPPLY_MAX = 42;
    public const int KEY_CONTAINER_TYPE = 44;
    public const int KEY_CONTAINER_SIZE = 45;
    public const int KEY_CONTAINER_STRENGTH_MODIFIER = 46;
    public const int KEY_WIDTH = 53;
    public const int KEY_HEIGHT = 54;
    public const int KEY_FUSE_TIME = 55;
    public const int KEY_RIDER_SEAT_POSITION = 56;
    public const int KEY_RIDER_ROTATION_LOCKED = 57;
    public const int KEY_RIDER_MAX_ROTATION = 58;
    public const int KEY_RIDER_MIN_ROTATION = 59;
    public const int KEY_RIDER_SEAT_ROTATION_OFFSET = 60;
    public const int KEY_CLOUD_RADIUS = 61;
    public const int KEY_CLOUD_WAITING = 62;
    public const int KEY_CLOUD_PARTICLE = 63;
    public const int KEY_CONTROLLING_RIDER_SEAT = 74;
    public const int KEY_ALWAYS_SHOW_NAME_TAG = 81;
    public const int KEY_FLAGS_TWO = 92;
    public const int KEY_CLOUD_DURATION = 95;
    public const int KEY_CLOUD_SPAWN_TIME = 96;
    public const int KEY_CLOUD_RADIUS_PER_TICK = 97;
    public const int KEY_CLOUD_RADIUS_ON_PICKUP = 98;
    public const int KEY_CLOUD_PICKUP_COUNT = 99;
    public const int KEY_INTERACT_TEXT = 100;
    public const int KEY_IS_BUOYANT = 119;
    public const int KEY_BUOYANCY_DATA = 121;
    public const int KEY_VISIBLE_MOB_EFFECTS = 131;

    public const int TYPE_BYTE = 0;
    public const int TYPE_SHORT = 1;
    public const int TYPE_INT = 2;
    public const int TYPE_FLOAT = 3;
    public const int TYPE_STRING = 4;
    public const int TYPE_POSITION = 6;
    public const int TYPE_LONG = 7;
    public const int TYPE_VECTOR3 = 8;

    /** bits of KEY_PLAYER_FLAGS */
    public const int PLAYER_FLAG_SLEEPING = 1;

    public const int FLAG_ON_FIRE = 0;
    public const int FLAG_SNEAKING = 1;
    public const int FLAG_RIDING = 2;
    public const int FLAG_SPRINTING = 3;
    public const int FLAG_USING_ITEM = 4;
    public const int FLAG_INVISIBLE = 5;
    public const int FLAG_IN_LOVE = 7;
    public const int FLAG_POWERED = 9;
    public const int FLAG_IGNITED = 10;
    public const int FLAG_BABY = 11;
    public const int FLAG_SHOW_NAME = 14;
    public const int FLAG_CRITICAL = 13;
    public const int FLAG_ALWAYS_SHOW_NAME = 15;
    public const int FLAG_WALL_CLIMBING = 18;
    public const int FLAG_CAN_CLIMB = 19;
    public const int FLAG_ANGRY = 25;
    public const int FLAG_SHEARED = 31;
    public const int FLAG_BREATHING = 35;
    public const int FLAG_STACKABLE = 37;
    public const int FLAG_SHAKING = 40;
    public const int FLAG_HAS_COLLISION = 48;
    public const int FLAG_HAS_GRAVITY = 49;
    public const int FLAG_COLLIDABLE = 121;

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

    public function setVector3(int $key, float $x, float $y, float $z) : void{
        $this->values[$key] = [self::TYPE_VECTOR3, [$x, $y, $z]];
    }

    public function setBlockPosition(int $key, int $x, int $y, int $z) : void{
        $this->values[$key] = [self::TYPE_POSITION, [$x, $y, $z]];
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
                self::TYPE_POSITION => $this->writePosition($writer, $value),
                self::TYPE_LONG => $writer->writeVarLong($value),
                self::TYPE_VECTOR3 => $this->writeVector3($writer, $value)
            };
        }
    }

    /**
     * @param array{float, float, float} $vector
     */
    private function writeVector3(BinaryWriter $writer, array $vector) : void{
        $writer->writeFloat($vector[0]);
        $writer->writeFloat($vector[1]);
        $writer->writeFloat($vector[2]);
    }

    /**
     * @param array{int, int, int} $position
     */
    private function writePosition(BinaryWriter $writer, array $position) : void{
        $writer->writeVarInt($position[0]);
        $writer->writeVarInt($position[1]);
        $writer->writeVarInt($position[2]);
    }
}