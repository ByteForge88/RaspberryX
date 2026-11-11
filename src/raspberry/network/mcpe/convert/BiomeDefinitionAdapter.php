<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\convert;

use raspberry\world\biome\Biome;
use raspberry\world\biome\BiomeRegistry;

use raspberry\network\mcpe\protocol\BiomeDefinitionListPacket;
use raspberry\network\mcpe\protocol\types\biome\BiomeDefinitionData;

use function array_map;
use function array_values;

class BiomeDefinitionAdapter{

    public static function createDefinitionList() : BiomeDefinitionListPacket{
        return BiomeDefinitionListPacket::create(array_values(array_map(fn(Biome $biome) : BiomeDefinitionData => new BiomeDefinitionData(
            $biome->getName(),
            BiomeDefinitionData::VANILLA_ID,
            $biome->getTemperature(),
            $biome->getDownfall(),
            0.0,
            $biome->getDepth(),
            $biome->getScale(),
            $biome->getMapWaterColor(),
            $biome->hasPrecipitation(),
            $biome->getTags()
        ), BiomeRegistry::getAll())));
    }
}