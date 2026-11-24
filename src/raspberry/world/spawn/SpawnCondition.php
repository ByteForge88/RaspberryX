<?php

declare(strict_types=1);

namespace raspberry\world\spawn;

use raspberry\block\BlockState;

use raspberry\world\Difficulty;
use raspberry\world\World;
use raspberry\world\biome\Biome;

use function count;
use function in_array;
use function max;
use function mt_rand;

class SpawnCondition{

    public const string SURFACE = "surface";
    public const string UNDERGROUND = "underground";
    public const string UNDERWATER = "underwater";
    public const string LAVA = "lava";
    public const string AIR = "air";

    public const string ENTITY_BORN = "minecraft:entity_born";

    private string $type;

    private string $population;

    private int $weight;

    private int $rarity;

    /** @var list<array{min: int, max: int, event?: string, skip?: int, initial_event?: string, initial_count?: int}> */
    private array $herds;

    /** @var list<string> */
    private array $places;

    /** @var array{min: int, max: int, weather: bool}|null */
    private ?array $brightness;

    private int $minDifficulty;

    private int $maxDifficulty;

    private ?int $minY;

    private ?int $maxY;

    /** @var array<string, true>|null */
    private ?array $on;

    /** @var array<string, true> */
    private array $notOn;

    /** @var array<string, mixed>|null */
    private ?array $biomes;

    /** @var array<string, int> */
    private array $density;

    private ?int $minDistance;

    private ?int $maxDistance;

    /** @var list<array{weight: int, type?: string, event?: string}> */
    private array $permute;

    private ?string $event;

    private bool $supported;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(string $type, string $population, array $data){
        $this->type = $type;
        $this->population = $population;
        $this->weight = $data["weight"] ?? 0;
        $this->rarity = max(1, $data["rarity"] ?? 1);
        $this->herds = $data["herds"] ?? [["min" => 1, "max" => 1]];
        $this->places = $data["places"] ?? [];
        $this->brightness = $data["brightness"] ?? null;
        $this->minDifficulty = $data["difficulty"]["min"] ?? Difficulty::PEACEFUL->value;
        $this->maxDifficulty = $data["difficulty"]["max"] ?? Difficulty::HARD->value;
        $this->minY = $data["height"]["min"] ?? null;
        $this->maxY = $data["height"]["max"] ?? null;
        $this->on = isset($data["on"]) ? self::toSet($data["on"]) : null;
        $this->notOn = self::toSet($data["not_on"] ?? []);
        $this->biomes = $data["biomes"] ?? null;
        $this->density = $data["density"] ?? [];
        $this->minDistance = $data["distance"]["min"] ?? null;
        $this->maxDistance = $data["distance"]["max"] ?? null;
        $this->permute = $data["permute"] ?? [];
        $this->event = $data["event"] ?? null;
        $this->supported = !isset($data["requires"]);
    }

    /**
     * @param list<string> $names
     * @return array<string, true>
     */
    private static function toSet(array $names) : array{
        $set = [];

        foreach($names as $name){
            $set[$name] = true;
        }

        return $set;
    }

    public function getType() : string{
        return $this->type;
    }

    public function getPopulation() : string{
        return $this->population;
    }

    public function getWeight() : int{
        return $this->weight;
    }

    public function getEvent() : ?string{
        return $this->event;
    }

    public function isSupported() : bool{
        return $this->supported && $this->weight > 0;
    }

    public function allowsPlace(string $column, string $medium) : bool{
        $wanted = in_array(self::UNDERWATER, $this->places, true) ? self::UNDERWATER : (in_array(self::LAVA, $this->places, true) ? self::LAVA : self::AIR);

        if($medium !== $wanted){
            return false;
        }

        $anyColumn = !in_array(self::SURFACE, $this->places, true) && !in_array(self::UNDERGROUND, $this->places, true);
        return $anyColumn || in_array($column, $this->places, true);
    }

    public function hasDensityLimit() : bool{
        return $this->density !== [];
    }

    public function passesRarity() : bool{
        return $this->rarity === 1 || mt_rand(1, $this->rarity) === 1;
    }

    public function allowsDifficulty(Difficulty $difficulty) : bool{
        return $difficulty->value >= $this->minDifficulty && $difficulty->value <= $this->maxDifficulty;
    }

    public function allowsHeight(int $y) : bool{
        return ($this->minY === null || $y >= $this->minY) && ($this->maxY === null || $y <= $this->maxY);
    }

    public function allowsBiome(Biome $biome, int $y) : bool{
        return $this->biomes === null || self::testBiome($this->biomes, $biome, $y);
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function testBiome(array $node, Biome $biome, int $y) : bool{
        if(isset($node["all"])){
            foreach($node["all"] as $child){
                if(!self::testBiome($child, $biome, $y)){
                    return false;
                }
            }

            return true;
        }

        if(isset($node["any"])){
            foreach($node["any"] as $child){
                if(self::testBiome($child, $biome, $y)){
                    return true;
                }
            }

            return false;
        }

        if(isset($node["none"])){
            foreach($node["none"] as $child){
                if(self::testBiome($child, $biome, $y)){
                    return false;
                }
            }

            return true;
        }

        $result = isset($node["snow"]) ? $biome->canSnowAt($y) === $node["snow"] : $biome->hasTag($node["tag"]);
        return $result === ($node["is"] ?? true);
    }

    public function allowsGround(BlockState $below) : bool{
        $name = $below->getName();
        return !isset($this->notOn[$name]) && ($this->on === null || isset($this->on[$name]));
    }

    public function allowsBrightness(World $world, int $x, int $y, int $z) : bool{
        $block = $world->getBlockLight($x, $y, $z);

        if($this->population === SpawnRules::MONSTER && $block > 0){
            return false;
        }

        if($this->brightness === null){
            return true;
        }

        $brightness = max($block, $world->getSkyLight($x, $y, $z) - $world->getSkyDarken($this->brightness["weather"]));
        return $brightness >= $this->brightness["min"] && $brightness <= $this->brightness["max"];
    }

    public function allowsDistance(float $distanceSquared, int $min) : bool{
        $min = $this->minDistance ?? $min;
        return $distanceSquared > $min ** 2 && ($this->maxDistance === null || $distanceSquared <= $this->maxDistance ** 2);
    }

    public function getDensityLimit(string $place) : ?int{
        return $this->density[$place] ?? null;
    }

    /**
     * @return array{min: int, max: int, event?: string, skip?: int, initial_event?: string, initial_count?: int}
     */
    public function pickHerd() : array{
        return $this->herds[mt_rand(0, count($this->herds) - 1)];
    }

    /**
     * @return array{string, ?string}
     */
    public function pickType() : array{
        if($this->permute === []){
            return [$this->type, null];
        }

        $total = 0;

        foreach($this->permute as $entry){
            $total += $entry["weight"];
        }

        $roll = mt_rand(0, max(0, $total - 1));

        foreach($this->permute as $entry){
            if(($roll -= $entry["weight"]) < 0){
                return [$entry["type"] ?? $this->type, $entry["event"] ?? null];
            }
        }

        return [$this->type, null];
    }
}