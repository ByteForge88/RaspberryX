<?php

declare(strict_types=1);

namespace raspberry\nbt\tag;

use raspberry\nbt\NbtException;

use function strlen;

class StringTag extends Tag{

    /** strings are prefixed with an unsigned 16-bit length */
    public const MAX_LENGTH = 65535;

    private string $value;

    public function __construct(string $value){
        if(strlen($value) > self::MAX_LENGTH){
            throw new NbtException("StringTag is longer than " . self::MAX_LENGTH . " bytes");
        }

        $this->value = $value;
    }

    public function getType() : int{
        return self::TAG_STRING;
    }

    public function getValue() : string{
        return $this->value;
    }
}