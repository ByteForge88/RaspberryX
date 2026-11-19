<?php

declare(strict_types=1);

namespace raspberry\entity\monster;

use raspberry\Server;

use raspberry\entity\EntityIds;
use raspberry\entity\Living;
use raspberry\entity\effect\Effect;
use raspberry\entity\effect\EffectInstance;

use raspberry\world\Difficulty;

class CaveSpider extends Spider{

    public function getNetworkTypeId() : string{
        return EntityIds::CAVE_SPIDER;
    }

    protected function getBaseWidth() : float{
        return 0.7;
    }

    protected function getBaseHeight() : float{
        return 0.5;
    }

    protected function getDefaultMaxHealth() : float{
        return 12.0;
    }

    /**
     * Poisons what it bites, for 7 seconds on normal and 15 on hard.
     */
    protected function onHurtTarget(Living $target) : void{
        $seconds = match(Server::getInstance()->getDifficulty()){
            Difficulty::NORMAL => 7,
            Difficulty::HARD => 15,
            default => 0
        };

        if($seconds > 0){
            $target->getEffects()->add(new EffectInstance(Effect::POISON, $seconds * 20));
        }
    }
}