<?php

declare(strict_types=1);

namespace raspberry\nbt\tag;

use raspberry\nbt\NbtException;

use function array_values;
use function is_int;

class LongArrayTag extends Tag{

    /** @var list<int> */
    private array $value;

    /**
     * @param list<int> $value
     */
    public function __construct(array $value){
        foreach($value as $number){
            if(!is_int($number)){
                throw new NbtException("LongArrayTag only holds long values");
            }
        }

        $this->value = array_values($value);
    }

    public function getType() : int{
        return self::TAG_LONG_ARRAY;
    }

    /**
     * @return list<int>
     */
    public function getValue() : array{
        return $this->value;
    }
}