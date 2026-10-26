<?php

declare(strict_types=1);

namespace raspberry\block;

use InvalidArgumentException;
use OutOfBoundsException;

use function get_debug_type;
use function implode;
use function is_bool;
use function is_int;
use function is_string;
use function json_encode;
use function ksort;
use function preg_match;

/**
 * Property values follow Bedrock's NBT types:
 *   string -> TAG_String (e.g. "minecraft:cardinal_direction" => "north")
 *   int    -> TAG_Int    (e.g. "weirdo_direction" => 2)
 *   bool   -> TAG_Byte   (e.g. "upside_down_bit" => true)
 */
class BlockState{

    private string $name;

    /** @var array<string, string|int|bool> */
    private array $properties;

    private string $key;

    /**
     * @param array<string, string|int|bool> $properties
     */
    public function __construct(string $name, array $properties = []){
        if(preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $name) !== 1){
            throw new InvalidArgumentException("Invalid block name '{$name}', expected namespace:name");
        }

        foreach($properties as $property => $value){
            if(!is_string($property) || $property === ""){
                throw new InvalidArgumentException("Block property names must be non-empty strings");
            }

            if(!is_string($value) && !is_int($value) && !is_bool($value)){
                throw new InvalidArgumentException("Property '{$property}' must be string, int or bool, got " . get_debug_type($value));
            }
        }

        ksort($properties, SORT_STRING);

        $this->name = $name;
        $this->properties = $properties;
        $this->key = self::buildKey($name, $properties);
    }

    public function getName() : string{
        return $this->name;
    }

    /**
     * @return array<string, string|int|bool>
     */
    public function getProperties() : array{
        return $this->properties;
    }

    public function hasProperty(string $property) : bool{
        return isset($this->properties[$property]);
    }

    public function getProperty(string $property) : string|int|bool{
        return $this->properties[$property] ?? throw new OutOfBoundsException("Block {$this->name} has no property '{$property}'");
    }

    /**
     * Returns a copy with one property changed. The property must already exist
     * and keep its type, because Bedrock blocks have a fixed set of properties.
     */
    public function withProperty(string $property, string|int|bool $value) : self{
        $current = $this->getProperty($property);

        if(get_debug_type($current) !== get_debug_type($value)){
            throw new InvalidArgumentException("Property '{$property}' of {$this->name} must stay " . get_debug_type($current));
        }

        if($current === $value){
            return $this;
        }

        $properties = $this->properties;
        $properties[$property] = $value;

        return new self($this->name, $properties);
    }

    /**
     * Unique, stable string for this exact state. Useful as an array key for palettes.
     * Example: minecraft:oak_stairs[upside_down_bit = false, weirdo_direction = 2]
     */
    public function getKey() : string{
        return $this->key;
    }

    public function equals(self $other) : bool{
        return $this->key === $other->key;
    }

    public function __toString() : string{
        return $this->key;
    }

    /**
     * @param array<string, string|int|bool> $properties
     */
    private static function buildKey(string $name, array $properties) : string{
        if($properties === []){
            return $name;
        }

        $parts = [];

        foreach($properties as $property => $value){
            $parts[] = $property . "=" . json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }

        return $name . "[" . implode(",", $parts) . "]";
    }
}