<?php

declare(strict_types=1);

namespace raspberry\entity;

use raspberry\math\Vector3;

use raspberry\utils\Uuid;

use raspberry\world\World;

use function max;
use function min;

/**
 * TODO: inventory, hunger, experience
 */
class Human extends Living{

    public const EYE_HEIGHT = 1.62;

    public const WIDTH = 0.6;
    public const HEIGHT = 1.8;

    protected Uuid $uuid;

    protected Skin $skin;

    public const MAX_FOOD = 20;

    /** saturation can never be higher than the food level */
    public const DEFAULT_SATURATION = 5.0;

    /** exhaustion builds up from actions; at 4 it uses up saturation or food (once hunger is implemented) */
    public const MAX_EXHAUSTION = 4.0;

    protected int $food = self::MAX_FOOD;

    protected float $saturation = self::DEFAULT_SATURATION;

    protected float $exhaustion = 0.0;

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

    public function getFood() : int{
        return $this->food;
    }

    public function setFood(int $food) : void{
        $this->food = max(0, min($food, self::MAX_FOOD));
        $this->saturation = min($this->saturation, $this->food);
        $this->onAttributesChanged();
    }

    public function getSaturation() : float{
        return $this->saturation;
    }

    public function setSaturation(float $saturation) : void{
        $this->saturation = max(0.0, min($saturation, $this->food));
        $this->onAttributesChanged();
    }

    public function getExhaustion() : float{
        return $this->exhaustion;
    }

    public function setExhaustion(float $exhaustion) : void{
        $this->exhaustion = max(0.0, min($exhaustion, self::MAX_EXHAUSTION));
        $this->onAttributesChanged();
    }

    /**
     * The name shown above the head.
     */
    public function getNameTag() : string{
        return "";
    }
}