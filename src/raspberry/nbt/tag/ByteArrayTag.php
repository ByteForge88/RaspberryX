<?php

declare(strict_types=1);

namespace raspberry\nbt\tag;

/**
 * Raw bytes, kept as a PHP string.
 */
class ByteArrayTag extends Tag{

    private string $value;

    public function __construct(string $value){
        $this->value = $value;
    }

    public function getType() : int{
        return self::TAG_BYTE_ARRAY;
    }

    public function getValue() : string{
        return $this->value;
    }
}