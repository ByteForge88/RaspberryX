<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types\biome;

class BiomeDefinitionData{

    /** the ID vanilla biomes are sent with, the client then finds the biome by name */
    public const int VANILLA_ID = 0xffff;

    public string $name;

    public int $id;

    public float $temperature;

    public float $downfall;

    /** 0 to 1, how frozen the leaves look */
    public float $foliageSnow;

    public float $depth;

    public float $scale;

    /** ARGB */
    public int $mapWaterColor;

    public bool $rain;

    /** @var list<string>|null */
    public ?array $tags;

    /**
     * @param list<string>|null $tags
     */
    public function __construct(string $name, int $id, float $temperature, float $downfall, float $foliageSnow, float $depth, float $scale, int $mapWaterColor, bool $rain, ?array $tags){
        $this->name = $name;
        $this->id = $id;
        $this->temperature = $temperature;
        $this->downfall = $downfall;
        $this->foliageSnow = $foliageSnow;
        $this->depth = $depth;
        $this->scale = $scale;
        $this->mapWaterColor = $mapWaterColor;
        $this->rain = $rain;
        $this->tags = $tags;
    }
}