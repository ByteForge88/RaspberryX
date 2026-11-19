<?php

declare(strict_types=1);

namespace raspberry\world\biome;

use function max;
use function array_fill_keys;

class Biome{

    /** below this it snows instead of rains */
    public const float SNOW_TEMPERATURE = 0.15;

    /** the land gets colder from here up */
    public const int SEA_LEVEL = 64;

    private string $name;

    private int $id;

    private float $temperature;

    private float $downfall;

    private float $minSnow;

    private float $maxSnow;

    private float $depth;

    private float $scale;

    private int $mapWaterColor;

    private bool $rain;

    /** @var list<string> */
    private array $tags;

    /** @var array<string, true> */
    private array $tagLookup;

    /**
     * @param list<string> $tags
     */
    public function __construct(string $name, int $id, float $temperature, float $downfall, float $minSnow, float $maxSnow, float $depth, float $scale, int $mapWaterColor, bool $rain, array $tags){
        $this->name = $name;
        $this->id = $id;
        $this->temperature = $temperature;
        $this->downfall = $downfall;
        $this->minSnow = $minSnow;
        $this->maxSnow = $maxSnow;
        $this->depth = $depth;
        $this->scale = $scale;
        $this->mapWaterColor = $mapWaterColor;
        $this->rain = $rain;
        $this->tags = $tags;
        $this->tagLookup = array_fill_keys($tags, true);
    }

    /** with the namespace, e.g. "minecraft:swampland" */
    public function getName() : string{
        return $this->name;
    }

    public function getId() : int{
        return $this->id;
    }

    public function getTemperature() : float{
        return $this->temperature;
    }

    /**
     * The temperature at a height: above sea level it drops the higher it goes, which is why
     * mountain tops get snow in a biome that rains below.
     */
    public function getTemperatureAt(int $y) : float{
        return $this->temperature - max(0, $y - self::SEA_LEVEL) * 0.05 / 30;
    }

    public function getDownfall() : float{
        return $this->downfall;
    }

    /** how deep snow piles up in this biome during snowfall, in blocks */
    public function getMinSnow() : float{
        return $this->minSnow;
    }

    public function getMaxSnow() : float{
        return $this->maxSnow;
    }

    public function getDepth() : float{
        return $this->depth;
    }

    public function getScale() : float{
        return $this->scale;
    }

    /** ARGB, how water looks on maps */
    public function getMapWaterColor() : int{
        return $this->mapWaterColor;
    }

    /** whether anything falls from the sky here at all, deserts and the nether have none */
    public function hasPrecipitation() : bool{
        return $this->rain;
    }

    public function canRainAt(int $y) : bool{
        return $this->rain && $this->getTemperatureAt($y) >= self::SNOW_TEMPERATURE;
    }

    public function canSnowAt(int $y) : bool{
        return $this->rain && $this->getTemperatureAt($y) < self::SNOW_TEMPERATURE;
    }

    /**
     * @return list<string>
     */
    public function getTags() : array{
        return $this->tags;
    }

    /** $tag is one of BiomeTags */
    public function hasTag(string $tag) : bool{
        return isset($this->tagLookup[$tag]);
    }

    /**
     * The look pigs, cows and chickens spawn with here.
     */
    public function getFarmAnimalVariant() : ClimateVariant{
        return ClimateVariant::forFarmAnimals($this);
    }
}