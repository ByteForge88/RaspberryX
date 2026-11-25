<?php

declare(strict_types=1);

namespace raspberry\entity\monster;

use raspberry\entity\EntityIds;
use raspberry\entity\ai\Behavior;

class Endermite extends Monster{

    private const float TARGET_RANGE = 16.0;

    public function getNetworkTypeId() : string{
        return EntityIds::ENDERMITE;
    }

    protected function getBaseWidth() : float{
        return 0.4;
    }

    protected function getBaseHeight() : float{
        return 0.3;
    }

    protected function getDefaultMaxHealth() : float{
        return 8.0;
    }

    protected function getDefaultMovementSpeed() : float{
        return 0.25;
    }

    protected function getAttackDamage() : float{
        return 2.0;
    }

    public function isArthropod() : bool{
        return true;
    }

    public function getXpReward() : int{
        return 3;
    }

    public function getBehaviors() : array{
        return [
            Behavior::float(0),
            Behavior::meleeAttack(3, 1.0),
            Behavior::nearestAttackableTarget(5, self::TARGET_RANGE),
            Behavior::nearestAttackableMob(5, EntityIds::ENDERMAN, self::TARGET_RANGE),
            Behavior::randomStroll(6, 1.0)
        ];
    }

    protected function getDrops(int $looting) : array{
        return [];
    }
}