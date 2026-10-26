<?php

declare(strict_types=1);

namespace raspberry\entity;

use InvalidArgumentException;

use function count;
use function strlen;

class Skin{

    public const ARM_SIZE_SLIM = "slim";
    public const ARM_SIZE_WIDE = "wide";

    public string $skinId;
    public string $playFabId;

    /** JSON telling the client which geometry to use, e.g. {"geometry":{"default":"geometry.humanoid.custom"}} */
    public string $resourcePatch;

    public int $imageWidth;
    public int $imageHeight;
    public string $imageData;

    /** @var list<array{width: int, height: int, data: string, type: int, frames: float, expression: int}> */
    public array $animations = [];

    public int $capeWidth = 0;
    public int $capeHeight = 0;
    public string $capeData = "";
    public string $capeId = "";

    /** custom geometry JSON; empty for the built-in models */
    public string $geometryData = "";
    public string $geometryDataEngineVersion = "";
    public string $animationData = "";

    public string $fullId = "";
    public string $armSize = self::ARM_SIZE_WIDE;

    public bool $premium = false;
    public bool $persona = false;
    public bool $capeOnClassicSkin = false;

    public function __construct(string $skinId, string $resourcePatch, int $imageWidth, int $imageHeight, string $imageData){
        $this->skinId = $skinId;
        $this->playFabId = "";
        $this->resourcePatch = $resourcePatch;
        $this->imageWidth = $imageWidth;
        $this->imageHeight = $imageHeight;
        $this->imageData = $imageData;
    }

    /**
     * Checks that every image has exactly width * height * 4 bytes, which the client requires.
     */
    public function validate() : void{
        self::checkImage("skin", $this->imageWidth, $this->imageHeight, $this->imageData, false);
        self::checkImage("cape", $this->capeWidth, $this->capeHeight, $this->capeData, true);

        foreach($this->animations as $index => $animation){
            self::checkImage("animation {$index}", $animation["width"], $animation["height"], $animation["data"], false);
        }

        if(count($this->animations) > 32){
            throw new InvalidArgumentException("A skin can have at most 32 animations");
        }
    }

    private static function checkImage(string $name, int $width, int $height, string $data, bool $mayBeEmpty) : void{
        if($mayBeEmpty && $width === 0 && $height === 0 && $data === ""){
            return;
        }

        if($width < 1 || $height < 1 || $width > 512 || $height > 512){
            throw new InvalidArgumentException("Invalid {$name} size {$width}x{$height}");
        }

        if(strlen($data) !== $width * $height * 4){
            throw new InvalidArgumentException("The {$name} image is {$width}x{$height} but has " . strlen($data) . " bytes instead of " . ($width * $height * 4));
        }
    }
}