<?php

declare(strict_types=1);

namespace raspberry\entity\projectile;

use raspberry\entity\Living;
use raspberry\entity\Mob;

use raspberry\math\Vector3;

/**
 * Snowballs and eggs: light, they push what they hit and break.
 */
abstract class Throwable extends Projectile{

    public const float VELOCITY = 1.5;

    protected function getGravity() : float{
        return 0.03;
    }

    protected function onHitEntity(Living $target, Vector3 $point) : void{
        $target->knockBack($this->motion->x, $this->motion->z);

        if($target instanceof Mob){
            $this->world->getMobEngine()->hurt($target, $this->getOwner());
        }

        $this->onBreak($point);
        $this->close();
    }

    protected function onHitBlock(int $x, int $y, int $z, Vector3 $point) : void{
        $this->onBreak($point);
        $this->close();
    }

    protected function onBreak(Vector3 $point) : void{

    }
}