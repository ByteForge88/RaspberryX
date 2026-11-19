<?php

declare(strict_types=1);

namespace raspberry\entity\monster;

use raspberry\entity\EntityIds;
use raspberry\entity\ai\Behavior;
use raspberry\entity\effect\Effect;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use raspberry\network\mcpe\protocol\types\EntityMetadata;

use function mt_rand;

class Spider extends Monster{

    public const float FOLLOW_RANGE = 16.0;

    private const int LIGHT_CHECK_TICKS = 10;

    private bool $bright = false;

    public function getNetworkTypeId() : string{
        return EntityIds::SPIDER;
    }

    protected function getBaseWidth() : float{
        return 1.4;
    }

    protected function getBaseHeight() : float{
        return 0.9;
    }

    protected function getDefaultMaxHealth() : float{
        return 16.0;
    }

    protected function getDefaultMovementSpeed() : float{
        return 0.3;
    }

    protected function getAttackDamage() : float{
        return 2.0;
    }

    public function isArthropod() : bool{
        return true;
    }

    public function canBeAffected(Effect $effect) : bool{
        return $effect !== Effect::POISON && parent::canBeAffected($effect);
    }

    protected function getAiFlags() : int{
        return self::AI_CLIMBER | ($this->bright ? self::AI_BRIGHT : 0);
    }

    public function getBehaviors() : array{
        return [
            Behavior::float(1),
            Behavior::hurtByTarget(1, self::FOLLOW_RANGE),
            Behavior::nearestAttackableTarget(2, self::FOLLOW_RANGE, true, 10, true),
            Behavior::leapAtTarget(3, 0.4),
            Behavior::meleeAttack(4, 1.0, true, true),
            Behavior::randomStroll(5, 0.8),
            Behavior::lookAtPlayer(6, 8.0),
            Behavior::randomLookAround(6)
        ];
    }

    public function tick(int $currentTick) : void{
        parent::tick($currentTick);

        if(!$this->closed && $this->isAlive() && $this->ticksLived % self::LIGHT_CHECK_TICKS === 0){
            $bright = $this->getBrightness() >= 0.5;

            if($bright !== $this->bright){
                $this->bright = $bright;
                $this->world->getMobEngine()->update($this);
            }
        }
    }

    public function createMetadata() : EntityMetadata{
        $metadata = parent::createMetadata();
        $metadata->setFlag(EntityMetadata::FLAG_WALL_CLIMBING, ($this->state & self::STATE_CLIMBING) !== 0);
        return $metadata;
    }

    public function onEngineMove(float $x, float $y, float $z, float $yaw, float $headYaw, float $pitch, int $state) : void{
        $climbing = ($this->state & self::STATE_CLIMBING) !== 0;
        parent::onEngineMove($x, $y, $z, $yaw, $headYaw, $pitch, $state);

        if($climbing !== (($this->state & self::STATE_CLIMBING) !== 0)){
            $this->sendMetadata();
        }
    }

    protected function getDrops(int $looting) : array{
        $drops = [];
        $string = self::lootCount(0, 2, $looting);

        if($string > 0){
            $drops[] = new Item(ItemIds::STRING, $string);
        }

        if($this->getKiller() !== null){
            $eyes = mt_rand(-1, 1) + mt_rand(0, $looting);

            if($eyes > 0){
                $drops[] = new Item(ItemIds::SPIDER_EYE, $eyes);
            }
        }

        return $drops;
    }
}