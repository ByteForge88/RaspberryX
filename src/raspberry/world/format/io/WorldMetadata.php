<?php

declare(strict_types=1);

namespace raspberry\world\format\io;

use raspberry\math\Vector3;

use UnexpectedValueException;

use function count;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;

class WorldMetadata{

    public const int FORMAT_VERSION = 1;

    public function __construct(
        public string $name,
        public string $generator,
        public string $generatorSettings,
        public int $createdAt,
        public string $blockDataVersion
    ){}

    public ?Vector3 $spawn = null;

    public int $seed = 0;

    /** the world clock: the time of day is this modulo 24000, the day count this divided by it */
    public int $time = 0;

    /** how many ticks the world has run, which nothing but running changes */
    public int $gameTime = 0;

    /** @var array<string, bool|int> */
    public array $gameRules = [];

    public bool $raining = false;

    /** ticks until it starts or stops raining, 0 to pick a new time */
    public int $rainTime = 0;

    public bool $thundering = false;

    /** ticks until a storm starts or stops, 0 to pick a new time */
    public int $thunderTime = 0;

    /** ticks of clear weather a command asked for */
    public int $clearWeatherTime = 0;

    /** @var list<array<string, mixed>> */
    public array $tickingAreas = [];

    /**
     * @return array<string, mixed>
     */
    public function toArray() : array{
        return [
            "formatVersion" => self::FORMAT_VERSION,
            "name" => $this->name,
            "generator" => $this->generator,
            "generatorSettings" => $this->generatorSettings,
            "createdAt" => $this->createdAt,
            "blockDataVersion" => $this->blockDataVersion,
            "seed" => $this->seed,
            "time" => $this->time,
            "gameTime" => $this->gameTime,
            "gameRules" => (object) $this->gameRules,
            "raining" => $this->raining,
            "rainTime" => $this->rainTime,
            "thundering" => $this->thundering,
            "thunderTime" => $this->thunderTime,
            "clearWeatherTime" => $this->clearWeatherTime,
            "tickingAreas" => $this->tickingAreas
        ] + ($this->spawn === null ? [] : ["spawn" => [$this->spawn->x, $this->spawn->y, $this->spawn->z]]);
    }

    /**
     * @param array $data
     * @return WorldMetadata
     */
    public static function fromArray(array $data) : self{
        $version = $data["formatVersion"] ?? null;

        if($version !== self::FORMAT_VERSION){
            throw new UnexpectedValueException("Unsupported world format version " . (is_int($version) ? $version : "(missing)"));
        }

        foreach(["name", "generator", "generatorSettings", "blockDataVersion"] as $key){
            if(!is_string($data[$key] ?? null)){
                throw new UnexpectedValueException("World metadata is missing '$key'");
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

        foreach(["raining", "thundering"] as $key){
            if(isset($data[$key])){
                if(!is_bool($data[$key])){
                    throw new UnexpectedValueException("World metadata '$key' must be true or false");
                }

                $metadata->{$key} = $data[$key];
            }
        }

        foreach(["time", "gameTime", "rainTime", "thunderTime", "clearWeatherTime"] as $key){
            if(isset($data[$key])){
                if(!is_int($data[$key]) || $data[$key] < 0){
                    throw new UnexpectedValueException("World metadata '$key' must be a whole number of 0 or more");
                }

                $metadata->{$key} = $data[$key];
            }
        }

        if(isset($data["gameRules"])){
            if(!is_array($data["gameRules"])){
                throw new UnexpectedValueException("World metadata 'gameRules' must be an object");
            }

            foreach($data["gameRules"] as $name => $value){
                if(is_string($name) && (is_bool($value) || is_int($value))){
                    $metadata->gameRules[$name] = $value;
                }
            }
        }

        if(isset($data["tickingAreas"])){
            if(!is_array($data["tickingAreas"])){
                throw new UnexpectedValueException("World metadata 'tickingAreas' must be a list");
            }

            foreach($data["tickingAreas"] as $area){
                if(!is_array($area)){
                    throw new UnexpectedValueException("World metadata 'tickingAreas' must be a list of objects");
                }

                $metadata->tickingAreas[] = $area;
            }
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