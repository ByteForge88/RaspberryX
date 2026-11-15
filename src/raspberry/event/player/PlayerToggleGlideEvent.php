<?php

namespace raspberry\event\player;

use raspberry\player\Player;

/**
 * A player started or stopped gliding. The client decides this, the event only tells.
 */
class PlayerToggleGlideEvent extends PlayerEvent{

    private bool $state;

    public function __construct(Player $player, bool $state){
        $this->player = $player;
        $this->state = $state;
    }

    public function isGliding() : bool{
        return $this->state;
    }
}