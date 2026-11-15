<?php

declare(strict_types=1);

namespace raspberry\event\player;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;

use raspberry\player\Player;

/**
 * The player and their saved data are loaded, the world is not sent yet. Their position can
 * still be changed with teleport(). Cancelling it disconnects them with the kick message.
 */
class PlayerLoginEvent extends PlayerEvent implements Cancellable{
    use CancellableTrait;

    private string $kickMessage;

    public function __construct(Player $player, string $kickMessage){
        $this->player = $player;
        $this->kickMessage = $kickMessage;
    }

    public function getKickMessage() : string{
        return $this->kickMessage;
    }

    public function setKickMessage(string $kickMessage) : void{
        $this->kickMessage = $kickMessage;
    }
}