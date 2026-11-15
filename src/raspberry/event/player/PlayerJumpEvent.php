<?php

declare(strict_types=1);

namespace raspberry\event\player;

use raspberry\player\Player;

/**
 * A player jumped.
 */
class PlayerJumpEvent extends PlayerEvent{

    public function __construct(Player $player){
        $this->player = $player;
    }
}