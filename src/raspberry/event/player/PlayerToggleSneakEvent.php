<?php

declare(strict_types=1);

namespace raspberry\event\player;

use raspberry\player\Player;

/**
 * A player started or stopped sneaking. The client decides this, the event only tells.
 */
class PlayerToggleSneakEvent extends PlayerEvent{

    private bool $state;

    public function __construct(Player $player, bool $state){
        $this->player = $player;
        $this->state = $state;
    }

    public function isSneaking() : bool{
        return $this->state;
    }
}