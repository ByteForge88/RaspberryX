<?php

declare(strict_types=1);

namespace raspberry\event\player;

use raspberry\player\Player;

/**
 * A player started or stopped sprinting. The client decides this, the event only tells.
 */
class PlayerToggleSprintEvent extends PlayerEvent{

    private bool $state;

    public function __construct(Player $player, bool $state){
        $this->player = $player;
        $this->state = $state;
    }

    public function isSprinting() : bool{
        return $this->state;
    }
}