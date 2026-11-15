<?php

declare(strict_types=1);

namespace raspberry\event\player;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;

use raspberry\player\Player;

/**
 * A player started or stopped flying. Cancelling it makes them land, or keeps them flying.
 */
class PlayerToggleFlightEvent extends PlayerEvent implements Cancellable{
    use CancellableTrait;

    private bool $flying;

    public function __construct(Player $player, bool $flying){
        $this->player = $player;
        $this->flying = $flying;
    }

    public function isFlying() : bool{
        return $this->flying;
    }
}