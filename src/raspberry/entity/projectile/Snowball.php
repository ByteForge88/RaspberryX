<?php

declare(strict_types=1);

namespace raspberry\entity\projectile;

use raspberry\entity\EntityIds;

class Snowball extends Throwable{

    public function getNetworkTypeId() : string{
        return EntityIds::SNOWBALL;
    }
}