<?php

declare(strict_types=1);

namespace raspberry\entity\projectile;

use raspberry\entity\EntityFactory;
use raspberry\entity\EntityIds;
use raspberry\entity\animal\Animal;

use raspberry\math\Vector3;

use function mt_getrandmax;
use function mt_rand;

class Egg extends Throwable{

    public function getNetworkTypeId() : string{
        return EntityIds::EGG;
    }

    /**
     * One in 8 eggs hatches a chick, and one in 32 of those four.
     */
    protected function onBreak(Vector3 $point) : void{
        if(mt_rand(0, 7) !== 0){
            return;
        }

        $chicks = mt_rand(0, 31) === 0 ? 4 : 1;

        for($i = 0; $i < $chicks; ++$i){
            $chick = EntityFactory::create(EntityIds::CHICKEN, $this->world, $point, mt_rand() / mt_getrandmax() * 360.0);

            if($chick instanceof Animal){
                $chick->setBaby(true);
            }
        }
    }
}