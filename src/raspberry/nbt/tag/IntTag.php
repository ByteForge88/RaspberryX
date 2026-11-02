<?php

declare(strict_types=1);

namespace raspberry\nbt\tag;

use raspberry\nbt\NbtException;

class IntTag extends Tag{

    private int $value;

    public function __construct(int $value){
        if($value < -2147483648 || $value > 2147483647){
            throw new NbtException("IntTag value {$value} is out of range");
        }

        $this->value = $value;
    }

    public function getType() : int{
        return self::TAG_INT;
    }

    public function getValue() : int{
        return $this->value;
    }
}