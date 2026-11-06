<?php

declare(strict_types=1);

namespace raspberry\entity\object;

use raspberry\entity\Entity;
use raspberry\entity\Human;
use raspberry\entity\Living;
use raspberry\entity\effect\EffectInstance;

use raspberry\item\Potion;

use raspberry\math\Vector3;

use raspberry\player\GameMode;

use raspberry\world\World;

use function abs;

class AreaEffectCloud extends Entity{

    public const DURATION = 600;

    /** the cloud does nothing for this long after it appears */
    public const WAIT_TICKS = 10;

    public const START_RADIUS = 3.0;

    /** each entity it gives effects to makes it smaller */
    public const RADIUS_ON_USE = -0.5;

    public const HEIGHT = 0.5;

    private const APPLY_EVERY = 5;

    /** how long before the same entity is affected again */
    private const REAPPLY_TICKS = 20;

    private const MIN_RADIUS = 0.5;

    private int $potionMeta;

    private ?Living $owner;

    /** @var list<EffectInstance> */
    private array $effects;

    private float $radius = self::START_RADIUS;

    /** the radius viewers were last sent */
    private float $sentRadius = self::START_RADIUS;

    private int $age = 0;

    /** @var array<int, int> */
    private array $affected = [];

    public function __construct(World $world, Vector3 $position, int $potionMeta, ?Living $owner){
        $this->potionMeta = $potionMeta;
        $this->owner = $owner;
        $this->effects = Potion::getEffects($potionMeta, 4);

        parent::__construct($world, $position);
    }

    public function getNetworkTypeId() : string{
        return "minecraft:area_effect_cloud";
    }

    public function getPotionMeta() : int{
        return $this->potionMeta;
    }

    public function getRadius() : float{
        return $this->radius;
    }

    public function getOwner() : ?Living{
        return $this->owner;
    }

    public function tick(int $currentTick) : void{
        if($this->closed){
            return;
        }

        if($this->age === 0){
            $this->updateViewers();
        }

        if(++$this->age >= self::WAIT_TICKS + self::DURATION){
            $this->close();
            return;
        }

        $this->radius -= self::START_RADIUS / self::DURATION;

        if($this->radius < self::MIN_RADIUS){
            $this->close();
            return;
        }

        if($this->age >= self::WAIT_TICKS && $this->age % self::APPLY_EVERY === 0){
            $this->applyEffects();

            if($this->closed){
                return;
            }
        }

        if(abs($this->sentRadius - $this->radius) >= 0.1){
            $this->sentRadius = $this->radius;

            foreach($this->viewers as $viewer){
                $viewer->getNetworkSession()->sendEntityMetadata($this);
            }
        }
    }

    private function applyEffects() : void{
        if($this->effects === []){
            return;
        }

        foreach($this->world->getPlayers() as $player){
            if($player->isDead() || !$player->isSpawned() || $player->getGameMode() === GameMode::SPECTATOR || ($this->affected[$player->getId()] ?? 0) > $this->age){
                continue;
            }

            $target = $player->getPosition();
            [$dx, $dz] = [$target->x - $this->position->x, $target->z - $this->position->z];

            if($dx * $dx + $dz * $dz > $this->radius ** 2 || $target->y + Human::HEIGHT < $this->position->y || $target->y > $this->position->y + self::HEIGHT){
                continue;
            }

            $this->affected[$player->getId()] = $this->age + self::REAPPLY_TICKS;

            foreach($this->effects as $effect){
                $player->getEffects()->add($effect->getEffect()->isInstant() ? $effect : clone $effect, false, 0.5);
            }

            $this->radius += self::RADIUS_ON_USE;

            if($this->radius < self::MIN_RADIUS){
                $this->close();
                return;
            }
        }
    }
}