<?php

declare(strict_types=1);

namespace raspberry\entity;

use raspberry\entity\damage\DamageSource;

use raspberry\math\Vector3;

use raspberry\utils\Uuid;

use raspberry\world\World;
use raspberry\world\Difficulty;

use function max;
use function min;

/**
 * TODO: inventory, experience
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

    /** exhaustion builds up from actions; every 4 uses up one saturation point, or a food point when saturation is empty */
    public const MAX_EXHAUSTION = 4.0;

    /** food 18 or more heals slowly */
    public const REGENERATION_FOOD = 18;

    /** the hunger cycle: slow healing and starvation happen once every 80 ticks (4 seconds) */
    private const FOOD_TICKS = 80;

    protected int $food = self::MAX_FOOD;

    protected float $saturation = self::DEFAULT_SATURATION;

    protected float $exhaustion = 0.0;

    /** position in the 80-tick hunger cycle, 1 to 80 */
    protected int $foodTickTimer = 1;

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
     * Makes the human hungrier: every 4 exhaustion uses up a saturation point, or a food point when
     * saturation is empty. Exhaustion itself is not shown, so only food and saturation changes are sent.
     */
    public function exhaust(float $amount) : void{
        if($amount <= 0){
            return;
        }

        $this->exhaustion += $amount;
        $changed = false;

        while($this->exhaustion >= self::MAX_EXHAUSTION){
            $this->exhaustion -= self::MAX_EXHAUSTION;
            $changed = true;

            if($this->saturation > 0){
                $this->saturation = max(0.0, $this->saturation - 1.0);
            }elseif($this->food > 0){
                --$this->food;
            }
        }

        if($changed){
            $this->onAttributesChanged();
        }
    }

    /**
     * Runs the hunger cycle once a tick:
     *   food 20 with saturation left: heals 1 every second
     *   food 18 or more: heals 1 every 4 seconds
     *   food 0: starvation hurts 1 every 4 seconds, down to the difficulty's limit
     *   peaceful: food also refills by 1 every half second
     *   Healing costs 6 exhaustion.
     */
    protected function tickHunger(Difficulty $difficulty) : void{
        $tick = $this->foodTickTimer;
        $peaceful = $difficulty->refillsFood();

        if($tick % 10 === 0 && ($peaceful || ($this->food >= self::MAX_FOOD && $this->saturation > 0))){
            if($peaceful && $this->food < self::MAX_FOOD){
                $this->setFood($this->food + 1);
            }

            if($tick % 20 === 0){
                $this->regenerate();
            }
        }

        if($tick === 1){
            if($this->food >= self::REGENERATION_FOOD){
                $this->regenerate();
            }elseif($this->food <= 0 && $this->health > $difficulty->getStarvationHealthLimit()){
                $this->damage(new DamageSource(DamageSource::CAUSE_STARVATION, 1.0));
            }
        }

        $this->foodTickTimer = $tick >= self::FOOD_TICKS ? 1 : $tick + 1;
    }

    private function regenerate() : void{
        if($this->isAlive() && $this->health < $this->maxHealth){
            $this->heal(1.0);
            $this->exhaust(6.0);
        }
    }

    /**
     * The name shown above the head.
     */
    public function getNameTag() : string{
        return "";
    }
}