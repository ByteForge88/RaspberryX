<?php

declare(strict_types=1);

namespace raspberry\nbt\tag;

use raspberry\nbt\NbtException;

class ByteTag extends Tag{

    private int $value;

    public function __construct(int $value){
        if($value < -128 || $value > 127){
            throw new NbtException("ByteTag value {$value} is out of range");
        }

        $this->value = $value;
    }

    public function getType() : int{
        return self::TAG_BYTE;
    }

    public function getValue() : int{
        return $this->value;
    }
}