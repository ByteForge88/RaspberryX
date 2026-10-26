<?php

declare(strict_types=1);

namespace raspberry\block;

use InvalidArgumentException;
use RuntimeException;
use UnexpectedValueException;

use function array_key_first;
use function array_map;
use function count;
use function dirname;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;

class BlockStateRegistry{

    private string $version;

    /** @var array<string, array<string, list<string|int|bool>>> */
    private array $blocks = [];

    /** @var array<string, BlockState> */
    private array $defaults = [];

    public function __construct(?string $path = null){
        $path ??= dirname(__DIR__, 3) . "/resources/vanilla/block_states.json";

        $contents = file_get_contents($path);

        if($contents === false){
            throw new RuntimeException("Cannot read block data: {$path}");
        }

        $data = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);

        if(!is_array($data) || !is_string($data["version"] ?? null) || !is_array($data["blocks"] ?? null)){
            throw new UnexpectedValueException("Block data has an invalid layout: {$path}");
        }

        $this->version = $data["version"];

        foreach($data["blocks"] as $name => $properties){
            if(!is_string($name) || !is_array($properties)){
                throw new UnexpectedValueException("Invalid block entry in {$path}");
            }

            $this->blocks[$name] = $properties;
        }
    }

    public function getVersion() : string{
        return $this->version;
    }

    public function getBlockCount() : int{
        return count($this->blocks);
    }

    public function isRegistered(string $name) : bool{
        return isset($this->blocks[$name]);
    }

    /**
     * @return array<string, list<string|int|bool>>
     */
    public function getProperties(string $name) : array{
        return $this->blocks[$name] ?? throw new InvalidArgumentException("Unknown block: {$name}");
    }

    public function getDefaultState(string $name) : BlockState{
        if(isset($this->defaults[$name])){
            return $this->defaults[$name];
        }

        $properties = [];

        foreach($this->getProperties($name) as $property => $values){
            $properties[$property] = $values[array_key_first($values)];
        }

        return $this->defaults[$name] = new BlockState($name, $properties);
    }

    /**
     * Builds a state from the default and overrides only the given properties.
     *
     * @param array<string, string|int|bool> $properties
     */
    public function get(string $name, array $properties = []) : BlockState{
        $state = $this->getDefaultState($name);

        foreach($properties as $property => $value){
            $state = $state->withProperty($property, $value);
        }

        $this->validate($state);

        return $state;
    }

    public function isValid(BlockState $state) : bool{
        $allowed = $this->blocks[$state->getName()] ?? null;

        if($allowed === null || count($allowed) !== count($state->getProperties())){
            return false;
        }

        foreach($state->getProperties() as $property => $value){
            if(!isset($allowed[$property]) || !in_array($value, $allowed[$property], true)){
                return false;
            }
        }

        return true;
    }

    public function validate(BlockState $state) : void{
        $name = $state->getName();
        $allowed = $this->blocks[$name] ?? throw new InvalidArgumentException("Unknown block: {$name}");

        foreach($allowed as $property => $values){
            if(!$state->hasProperty($property)){
                throw new InvalidArgumentException("{$state} is missing property '{$property}'");
            }
        }

        foreach($state->getProperties() as $property => $value){
            if(!isset($allowed[$property])){
                throw new InvalidArgumentException("{$name} has no property '{$property}'");
            }

            if(!in_array($value, $allowed[$property], true)){
                throw new InvalidArgumentException("Invalid value " . json_encode($value) . " for {$name} property '{$property}', allowed: " . implode(", ", array_map(json_encode(...), $allowed[$property])));
            }
        }
    }
}