<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\convert;

use raspberry\entity\Skin;

use InvalidArgumentException;
use stdClass;

use function base64_decode;
use function is_array;
use function is_bool;
use function is_int;
use function is_numeric;
use function is_string;
use function str_repeat;

class SkinAdapter{

    public const DEFAULT_RESOURCE_PATCH = '{"geometry":{"default":"geometry.humanoid.custom"}}';

    /**
     * @throws InvalidArgumentException when the skin data is missing or broken
     */
    public static function fromClientData(stdClass $data) : Skin{
        $skin = new Skin(
            self::string($data, "SkinId"),
            self::base64($data, "SkinResourcePatch"),
            self::int($data, "SkinImageWidth"),
            self::int($data, "SkinImageHeight"),
            self::base64($data, "SkinData")
        );

        $skin->playFabId = self::string($data, "PlayFabId", "");
        $skin->capeWidth = self::int($data, "CapeImageWidth", 0);
        $skin->capeHeight = self::int($data, "CapeImageHeight", 0);
        $skin->capeData = self::base64($data, "CapeData", "");
        $skin->capeId = self::string($data, "CapeId", "");
        $skin->geometryData = self::base64($data, "SkinGeometryData", "");
        $skin->geometryDataEngineVersion = self::base64($data, "SkinGeometryDataEngineVersion", "");
        $skin->animationData = self::base64($data, "SkinAnimationData", "");
        $skin->armSize = self::string($data, "ArmSize", Skin::ARM_SIZE_WIDE) === Skin::ARM_SIZE_SLIM ? Skin::ARM_SIZE_SLIM : Skin::ARM_SIZE_WIDE;
        $skin->premium = self::bool($data, "PremiumSkin");
        $skin->persona = self::bool($data, "PersonaSkin");
        $skin->capeOnClassicSkin = self::bool($data, "CapeOnClassicSkin");
        $skin->fullId = $skin->skinId . $skin->capeId;

        $animations = $data->AnimatedImageData ?? [];

        if(!is_array($animations)){
            throw new InvalidArgumentException("AnimatedImageData must be a list");
        }

        foreach($animations as $animation){
            if(!$animation instanceof stdClass){
                throw new InvalidArgumentException("Invalid skin animation");
            }

            $skin->animations[] = [
                "width" => self::int($animation, "ImageWidth"),
                "height" => self::int($animation, "ImageHeight"),
                "data" => self::base64($animation, "Image"),
                "type" => self::int($animation, "Type", 0),
                "frames" => (float) self::number($animation, "Frames", 1),
                "expression" => self::int($animation, "AnimationExpression", 0)
            ];
        }

        $skin->validate();
        return $skin;
    }

    /**
     * A plain grey player, used when a client sends a skin that cannot be used.
     */
    public static function defaultSkin() : Skin{
        $skin = new Skin("raspberryx_default", self::DEFAULT_RESOURCE_PATCH, 64, 64, str_repeat("\x80\x80\x80\xff", 64 * 64));
        $skin->fullId = $skin->skinId;
        return $skin;
    }

    private static function string(stdClass $data, string $key, ?string $default = null) : string{
        $value = $data->{$key} ?? $default;

        if(!is_string($value)){
            throw new InvalidArgumentException("Client data is missing {$key}");
        }

        return $value;
    }

    private static function int(stdClass $data, string $key, ?int $default = null) : int{
        $value = $data->{$key} ?? $default;

        if(!is_int($value)){
            throw new InvalidArgumentException("Client data is missing {$key}");
        }

        return $value;
    }

    private static function number(stdClass $data, string $key, int|float $default) : int|float{
        $value = $data->{$key} ?? $default;

        if(!is_numeric($value) || is_string($value)){
            throw new InvalidArgumentException("Client data has an invalid {$key}");
        }

        return $value;
    }

    private static function bool(stdClass $data, string $key) : bool{
        $value = $data->{$key} ?? false;

        return is_bool($value) && $value;
    }

    private static function base64(stdClass $data, string $key, ?string $default = null) : string{
        $value = $data->{$key} ?? null;

        if($value === null && $default !== null){
            return $default;
        }

        $decoded = is_string($value) ? base64_decode($value, true) : false;

        if($decoded === false){
            throw new InvalidArgumentException("Client data has an invalid {$key}");
        }

        return $decoded;
    }
}