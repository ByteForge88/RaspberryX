<?php

declare(strict_types=1);

namespace raspberry\entity\animal;

use raspberry\nbt\tag\CompoundTag;

use raspberry\world\biome\ClimateVariant;

use function mt_rand;

trait ClimateVariantTrait{

    protected ClimateVariant $variant = ClimateVariant::TEMPERATE;

    public function getVariant() : ClimateVariant{
        return $this->variant;
    }

    public function setVariant(ClimateVariant $variant) : void{
        $this->variant = $variant;
    }

    protected function pickVariant() : void{
        $position = $this->position;

        if($this->world->isChunkLoaded($position->getChunkX(), $position->getChunkZ())){
            $this->variant = $this->world->getBiome($position->getFloorX(), $position->getFloorY(), $position->getFloorZ())->getFarmAnimalVariant();
        }
    }

    public function getSyncedProperties() : array{
        return ["minecraft:climate_variant" => $this->variant->value];
    }

    protected function inherit(Animal $first, Animal $second) : void{
        $parent = mt_rand(0, 1) === 0 ? $first : $second;

        if($parent instanceof self){
            $this->variant = $parent->variant;
        }
    }

    protected function saveVariant(?CompoundTag $tag) : ?CompoundTag{
        $tag?->setString("ClimateVariant", $this->variant->value);
        return $tag;
    }

    protected function loadVariant(CompoundTag $tag) : void{
        $this->variant = ClimateVariant::tryFrom($tag->getString("ClimateVariant", "")) ?? $this->variant;
    }
}