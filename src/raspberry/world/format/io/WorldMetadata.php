<?php

declare(strict_types=1);

namespace raspberry\world\format\io;

use raspberry\math\Vector3;

use UnexpectedValueException;

use function count;
use function is_array;
use function is_float;
use function is_int;
use function is_string;

class WorldMetadata{

    public const FORMAT_VERSION = 1;

    public function __construct(
        public string $name,
        public string $generator,
        public string $generatorSettings,
        public int $createdAt,
        public string $blockDataVersion
    ){}

    public ?Vector3 $spawn = null;

    public int $seed = 0;

    /**
     * @return array<string, string|int>
     */
    public function toArray() : array{
        return [
            "formatVersion" => self::FORMAT_VERSION,
            "name" => $this->name,
            "generator" => $this->generator,
            "generatorSettings" => $this->generatorSettings,
            "createdAt" => $this->createdAt,
            "blockDataVersion" => $this->blockDataVersion,
            "seed" => $this->seed
        ] + ($this->spawn === null ? [] : ["spawn" => [$this->spawn->x, $this->spawn->y, $this->spawn->z]]);
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data) : self{
        $version = $data["formatVersion"] ?? null;

        if($version !== self::FORMAT_VERSION){
            throw new UnexpectedValueException("Unsupported world format version " . (is_int($version) ? $version : "(missing)"));
        }

        foreach(["name", "generator", "generatorSettings", "blockDataVersion"] as $key){
            if(!is_string($data[$key] ?? null)){
                throw new UnexpectedValueException("World metadata is missing '{$key}'");
            }
        }

        if(!is_int($data["createdAt"] ?? null)){
            throw new UnexpectedValueException("World metadata is missing 'createdAt'");
        }

        $metadata = new self($data["name"], $data["generator"], $data["generatorSettings"], $data["createdAt"], $data["blockDataVersion"]);

        if(isset($data["seed"])){
            if(!is_int($data["seed"])){
                throw new UnexpectedValueException("World metadata 'seed' must be a whole number");
            }

            $metadata->seed = $data["seed"];
        }

        if(isset($data["spawn"])){
            $spawn = $data["spawn"];

            if(!is_array($spawn) || count($spawn) !== 3 || !self::isNumber($spawn[0] ?? null) || !self::isNumber($spawn[1] ?? null) || !self::isNumber($spawn[2] ?? null)){
                throw new UnexpectedValueException("World metadata 'spawn' must be [x, y, z]");
            }

            $metadata->spawn = new Vector3((float) $spawn[0], (float) $spawn[1], (float) $spawn[2]);
        }

        return $metadata;
    }

    private static function isNumber(mixed $value) : bool{
        return is_int($value) || is_float($value);
    }
}