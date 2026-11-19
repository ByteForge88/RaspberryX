<?php

declare(strict_types=1);

namespace raspberry\entity\monster;

use raspberry\entity\EntityIds;
use raspberry\entity\Living;
use raspberry\entity\ai\Behavior;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use function mt_getrandmax;
use function mt_rand;

class Zombie extends Monster{

    public const float FOLLOW_RANGE = 35.0;

    private const array RARE_DROPS = [ItemIds::IRON_INGOT, ItemIds::CARROT, ItemIds::POTATO];

    public function getNetworkTypeId() : string{
        return EntityIds::ZOMBIE;
    }

    protected function getBaseWidth() : float{
        return 0.6;
    }

    protected function getBaseHeight() : float{
        return 1.95;
    }

    protected function getDefaultMaxHealth() : float{
        return 20.0;
    }

    protected function getDefaultMovementSpeed() : float{
        return $this->baby ? 0.345 : 0.23;
    }

    protected function getAttackDamage() : float{
        return 3.0;
    }

    public function isUndead() : bool{
        return true;
    }

    protected function burnsInDaylight() : bool{
        return true;
    }

    protected function getArmorValues() : array{
        return [2, 0.0];
    }

    public function setBaby(bool $baby) : void{
        parent::setBaby($baby);
        $this->updateEffectAttributes();
    }

    public function getXpReward() : int{
        return $this->baby ? 12 : 5;
    }

    public function getBehaviors() : array{
        return [
            Behavior::hurtByTarget(1, self::FOLLOW_RANGE),
            Behavior::meleeAttack(2, 1.0),
            Behavior::nearestAttackableTarget(2, self::FOLLOW_RANGE),
            Behavior::randomStroll(7, 1.0),
            Behavior::lookAtPlayer(8, 8.0),
            Behavior::randomLookAround(8)
        ];
    }

    /**
     * A burning zombie sets what it hits on fire, more often in harder worlds.
     */
    protected function onHurtTarget(Living $target) : void{
        $difficulty = self::getEffectiveDifficulty();

        if($this->isOnFire() && mt_rand() / mt_getrandmax() < $difficulty * 0.3){
            $target->setOnFire(2 * (int) $difficulty);
        }
    }

    protected function getDrops(int $looting) : array{
        $drops = [];
        $flesh = self::lootCount(0, 2, $looting);

        if($flesh > 0){
            $drops[] = new Item(ItemIds::ROTTEN_FLESH, $flesh);
        }

        if($this->getKiller() !== null && mt_rand() / mt_getrandmax() < 0.025 + 0.01 * $looting){
            $drops[] = new Item(self::RARE_DROPS[mt_rand(0, 2)]);
        }

        return $drops;
    }
}