<?php

declare(strict_types=1);

namespace raspberry\event\player;

use raspberry\entity\damage\DamageSource;

use raspberry\lang\Translation;

use raspberry\player\Player;

class PlayerDeathEvent extends PlayerEvent{

    private ?DamageSource $cause;

    private Translation|string|null $deathMessage;

    public function __construct(Player $player, ?DamageSource $cause, Translation|string|null $deathMessage){
        $this->player = $player;
        $this->cause = $cause;
        $this->deathMessage = $deathMessage;
    }

    public function getCause() : ?DamageSource{
        return $this->cause;
    }

    public function getDeathMessage() : Translation|string|null{
        return $this->deathMessage;
    }

    public function setDeathMessage(Translation|string|null $deathMessage) : void{
        $this->deathMessage = $deathMessage;
    }
}