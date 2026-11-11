<?php

declare(strict_types=1);

namespace raspberry\world\biome;

enum ClimateVariant : string{

    case TEMPERATE = "temperate";
    case WARM = "warm";
    case COLD = "cold";

    public static function forFarmAnimals(Biome $biome) : self{
        return match(true){
            $biome->hasTag(BiomeTags::SPAWNS_COLD_VARIANT_FARM_ANIMALS) => self::COLD,
            $biome->hasTag(BiomeTags::SPAWNS_WARM_VARIANT_FARM_ANIMALS) => self::WARM,
            default => self::TEMPERATE
        };
    }
}