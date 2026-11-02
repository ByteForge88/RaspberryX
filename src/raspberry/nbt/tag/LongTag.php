<?php

declare(strict_types=1);

namespace raspberry\nbt\tag;

class LongTag extends Tag{

    private int $value;

    public function __construct(int $value){
        $this->value = $value;
    }

    public function getType() : int{
        return self::TAG_LONG;
    }

    public function getValue() : int{
        return $this->value;
    }
}