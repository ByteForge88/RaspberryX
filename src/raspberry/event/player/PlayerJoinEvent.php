<?php

declare(strict_types=1);

namespace raspberry\event\player;

use raspberry\lang\Translation;

use raspberry\player\Player;

class PlayerJoinEvent extends PlayerEvent{

    private Translation|string|null $joinMessage;

    public function __construct(Player $player, Translation|string|null $joinMessage){
        $this->player = $player;
        $this->joinMessage = $joinMessage;
    }

    public function getJoinMessage() : Translation|string|null{
        return $this->joinMessage;
    }

    public function setJoinMessage(Translation|string|null $joinMessage) : void{
        $this->joinMessage = $joinMessage;
    }
}