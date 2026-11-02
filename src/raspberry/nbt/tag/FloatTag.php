<?php

declare(strict_types=1);

namespace raspberry\nbt\tag;

class FloatTag extends Tag{

    private float $value;

    public function __construct(float $value){
        $this->value = $value;
    }

    public function getType() : int{
        return self::TAG_FLOAT;
    }

    public function getValue() : float{
        return $this->value;
    }
}