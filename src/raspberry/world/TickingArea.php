<?php

declare(strict_types=1);

namespace raspberry\world;

use UnexpectedValueException;

use function is_bool;
use function is_int;
use function is_string;
use function max;
use function min;

class TickingArea{

    private string $name;

    private int $minChunkX;
    private int $minChunkZ;
    private int $maxChunkX;
    private int $maxChunkZ;

    private ?int $radius;

    private bool $preload;

    public function __construct(string $name, int $minChunkX, int $minChunkZ, int $maxChunkX, int $maxChunkZ, ?int $radius = null, bool $preload = false){
        $this->name = $name;
        $this->minChunkX = min($minChunkX, $maxChunkX);
        $this->minChunkZ = min($minChunkZ, $maxChunkZ);
        $this->maxChunkX = max($minChunkX, $maxChunkX);
        $this->maxChunkZ = max($minChunkZ, $maxChunkZ);
        $this->radius = $radius;
        $this->preload = $preload;
    }

    public static function box(string $name, int $fromX, int $fromZ, int $toX, int $toZ, bool $preload = false) : self{
        return new self($name, $fromX >> 4, $fromZ >> 4, $toX >> 4, $toZ >> 4, null, $preload);
    }

    public static function circle(string $name, int $centerX, int $centerZ, int $radius, bool $preload = false) : self{
        return new self($name, ($centerX >> 4) - $radius, ($centerZ >> 4) - $radius, ($centerX >> 4) + $radius, ($centerZ >> 4) + $radius, $radius, $preload);
    }

    public function getName() : string{
        return $this->name;
    }

    public function getMinChunkX() : int{
        return $this->minChunkX;
    }

    public function getMinChunkZ() : int{
        return $this->minChunkZ;
    }

    public function getMaxChunkX() : int{
        return $this->maxChunkX;
    }

    public function getMaxChunkZ() : int{
        return $this->maxChunkZ;
    }

    public function getRadius() : ?int{
        return $this->radius;
    }

    public function isCircle() : bool{
        return $this->radius !== null;
    }

    public function isPreload() : bool{
        return $this->preload;
    }

    public function setPreload(bool $preload) : void{
        $this->preload = $preload;
    }

    public function getChunkCount() : int{
        return ($this->maxChunkX - $this->minChunkX + 1) * ($this->maxChunkZ - $this->minChunkZ + 1);
    }

    public function contains(int $x, int $z) : bool{
        $chunkX = $x >> 4;
        $chunkZ = $z >> 4;

        return $chunkX >= $this->minChunkX && $chunkX <= $this->maxChunkX && $chunkZ >= $this->minChunkZ && $chunkZ <= $this->maxChunkZ;
    }

    /**
     * @return list<array{int, int}>
     */
    public function getChunks() : array{
        $chunks = [];

        for($chunkX = $this->minChunkX; $chunkX <= $this->maxChunkX; ++$chunkX){
            for($chunkZ = $this->minChunkZ; $chunkZ <= $this->maxChunkZ; ++$chunkZ){
                $chunks[] = [$chunkX, $chunkZ];
            }
        }

        return $chunks;
    }

    /**
     * @return array{name: string, minChunkX: int, minChunkZ: int, maxChunkX: int, maxChunkZ: int, radius?: int, preload: bool}
     */
    public function toArray() : array{
        return [
                "name" => $this->name,
                "minChunkX" => $this->minChunkX,
                "minChunkZ" => $this->minChunkZ,
                "maxChunkX" => $this->maxChunkX,
                "maxChunkZ" => $this->maxChunkZ,
                "preload" => $this->preload
            ] + ($this->radius === null ? [] : ["radius" => $this->radius]);
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data) : self{
        foreach(["minChunkX", "minChunkZ", "maxChunkX", "maxChunkZ"] as $key){
            if(!is_int($data[$key] ?? null)){
                throw new UnexpectedValueException("Ticking area '{$key}' must be a whole number");
            }
        }

        if(!is_string($data["name"] ?? null) || !is_bool($data["preload"] ?? null)){
            throw new UnexpectedValueException("Ticking areas need a name and a preload flag");
        }

        $radius = $data["radius"] ?? null;

        if($radius !== null && !is_int($radius)){
            throw new UnexpectedValueException("Ticking area 'radius' must be a whole number");
        }

        return new self($data["name"], $data["minChunkX"], $data["minChunkZ"], $data["maxChunkX"], $data["maxChunkZ"], $radius, $data["preload"]);
    }
}