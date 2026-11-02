<?php

declare(strict_types=1);

namespace raspberry\nbt\tag;

use raspberry\nbt\NbtException;

use function array_values;
use function is_int;

class IntArrayTag extends Tag{

    /** @var list<int> */
    private array $value;

    /**
     * @param list<int> $value
     */
    public function __construct(array $value){
        foreach($value as $number){
            if(!is_int($number) || $number < -2147483648 || $number > 2147483647){
                throw new NbtException("IntArrayTag only holds int values");
            }
        }

        $this->value = array_values($value);
    }

    public function getType() : int{
        return self::TAG_INT_ARRAY;
    }

    /**
     * @return list<int>
     */
    public function getValue() : array{
        return $this->value;
    }
}