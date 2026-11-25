<?php

declare(strict_types=1);

namespace raspberry\world;

use raspberry\world\biome\BiomeIds;

use function strtolower;
use function trim;

enum Dimension : int{

    case OVERWORLD = 0;
    case NETHER = 1;
    case THE_END = 2;

    public static function fromName(string $name) : ?self{
        return match(strtolower(trim($name))){
            "overworld" => self::OVERWORLD,
            "nether" => self::NETHER,
            "the_end", "end" => self::THE_END,
            default => null
        };
    }

    public function getName() : string{
        return strtolower($this->name);
    }

    public function getMinY() : int{
        return match($this){
            self::OVERWORLD => -64,
            self::NETHER, self::THE_END => 0
        };
    }

    public function getMaxY() : int{
        return match($this){
            self::OVERWORLD => 319,
            self::NETHER => 127,
            self::THE_END => 255
        };
    }

    public function getMinSubChunk() : int{
        return $this->getMinY() >> 4;
    }

    public function getSubChunkCount() : int{
        return ($this->getMaxY() - $this->getMinY() + 1) >> 4;
    }

    public function hasSkyLight() : bool{
        return $this === self::OVERWORLD;
    }

    public function hasWeather() : bool{
        return $this === self::OVERWORLD;
    }

    public function getFixedTime() : ?int{
        return match($this){
            self::OVERWORLD => null,
            self::NETHER => World::TIME_MIDNIGHT,
            self::THE_END => World::TIME_NOON
        };
    }

    public function getCoordinateScale() : float{
        return $this === self::NETHER ? 8.0 : 1.0;
    }

    public function getDefaultBiome() : int{
        return match($this){
            self::OVERWORLD => BiomeIds::PLAINS,
            self::NETHER => BiomeIds::HELL,
            self::THE_END => BiomeIds::THE_END
        };
    }

    public function getFolder() : string{
        return match($this){
            self::OVERWORLD => "",
            self::NETHER => "nether",
            self::THE_END => "the_end"
        };
    }
}