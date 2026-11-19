<?php

declare(strict_types=1);

namespace raspberry\nbt\tag;

abstract class Tag{

    public const int TAG_END = 0;
    public const int TAG_BYTE = 1;
    public const int TAG_SHORT = 2;
    public const int TAG_INT = 3;
    public const int TAG_LONG = 4;
    public const int TAG_FLOAT = 5;
    public const int TAG_DOUBLE = 6;
    public const int TAG_BYTE_ARRAY = 7;
    public const int TAG_STRING = 8;
    public const int TAG_LIST = 9;
    public const int TAG_COMPOUND = 10;
    public const int TAG_INT_ARRAY = 11;
    public const int TAG_LONG_ARRAY = 12;

    abstract public function getType() : int;

    abstract public function getValue() : mixed;

    public static function getTypeName(int $type) : string{
        return match($type){
            self::TAG_END => "End",
            self::TAG_BYTE => "Byte",
            self::TAG_SHORT => "Short",
            self::TAG_INT => "Int",
            self::TAG_LONG => "Long",
            self::TAG_FLOAT => "Float",
            self::TAG_DOUBLE => "Double",
            self::TAG_BYTE_ARRAY => "ByteArray",
            self::TAG_STRING => "String",
            self::TAG_LIST => "List",
            self::TAG_COMPOUND => "Compound",
            self::TAG_INT_ARRAY => "IntArray",
            self::TAG_LONG_ARRAY => "LongArray",
            default => "Unknown($type)"
        };
    }
}