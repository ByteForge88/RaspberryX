<?php

namespace raspberry\event\player;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;

use raspberry\player\Player;

/**
 * A player is about to be kicked by a command, a plugin or the server.
 */
class PlayerKickEvent extends PlayerEvent implements Cancellable{
    use CancellableTrait;

    private string $reason;

    public function __construct(Player $player, string $reason){
        $this->player = $player;
        $this->reason = $reason;
    }

    /**
     * What the player sees on the disconnect screen.
     */
    public function getReason() : string{
        return $this->reason;
    }

    public function setReason(string $reason) : void{
        $this->reason = $reason;
    }
}