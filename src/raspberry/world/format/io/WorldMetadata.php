<?php

declare(strict_types=1);

namespace raspberry\world\format\io;

use UnexpectedValueException;

use function is_int;
use function is_string;

/**
 * Settings fixed when a world is created. They are saved with the world, so changing
 * server.properties later does not change how an existing world generates new chunks.
 */
class WorldMetadata{

    public const FORMAT_VERSION = 1;

    public function __construct(
        public string $name,
        public string $generator,
        public string $generatorSettings,
        public int $createdAt,
        public string $blockDataVersion
    ){}

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
            "blockDataVersion" => $this->blockDataVersion
        ];
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

        return new self($data["name"], $data["generator"], $data["generatorSettings"], $data["createdAt"], $data["blockDataVersion"]);
    }
}