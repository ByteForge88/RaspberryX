<?php

declare(strict_types=1);

namespace raspberry\entity\monster;

use raspberry\entity\EntityIds;
use raspberry\entity\Living;
use raspberry\entity\effect\Effect;
use raspberry\entity\effect\EffectInstance;

use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

class Husk extends Zombie{

    private const int HUNGER_TICKS = 600;

    public function getNetworkTypeId() : string{
        return EntityIds::HUSK;
    }

    protected function burnsInDaylight() : bool{
        return false;
    }

    protected function getConversionType() : ?string{
        return EntityIds::ZOMBIE;
    }

    protected function getConversionSound() : string{
        return LevelSoundEvent::MOB_HUSK_CONVERT_TO_ZOMBIE;
    }

    protected function onHurtTarget(Living $target) : void{
        parent::onHurtTarget($target);
        $target->getEffects()->add(new EffectInstance(Effect::HUNGER, self::HUNGER_TICKS));
    }
}