<?php

declare(strict_types=1);

namespace raspberry\entity;

use raspberry\math\Vector3;

use raspberry\utils\Uuid;

use raspberry\world\World;

/**
 * TODO: inventory, hunger, experience
 */
class Human extends Living{

    public const EYE_HEIGHT = 1.62;

    public const WIDTH = 0.6;
    public const HEIGHT = 1.8;

    protected Uuid $uuid;

    protected Skin $skin;

    public function __construct(World $world, Vector3 $position, Skin $skin, ?Uuid $uuid = null){
        $this->skin = $skin;
        $this->uuid = $uuid ?? Uuid::random();

        parent::__construct($world, $position);
    }

    public function getNetworkTypeId() : string{
        return "minecraft:player";
    }

    public function getEyeHeight() : float{
        return self::EYE_HEIGHT;
    }

    public function getUuid() : Uuid{
        return $this->uuid;
    }

    public function getSkin() : Skin{
        return $this->skin;
    }

    /**
     * The name shown above the head.
     */
    public function getNameTag() : string{
        return "";
    }
}