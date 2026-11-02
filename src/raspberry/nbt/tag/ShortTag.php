<?php

declare(strict_types=1);

namespace raspberry\nbt\tag;

use raspberry\nbt\NbtException;

class ShortTag extends Tag{

    private int $value;

    public function __construct(int $value){
        if($value < -32768 || $value > 32767){
            throw new NbtException("ShortTag value {$value} is out of range");
        }

        $this->value = $value;
    }

    public function getType() : int{
        return self::TAG_SHORT;
    }

    public function getValue() : int{
        return $this->value;
    }
}