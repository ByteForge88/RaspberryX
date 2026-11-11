<?php

declare(strict_types=1);

namespace raspberry\event\player;

use raspberry\lang\Translation;

use raspberry\player\Player;

class PlayerQuitEvent extends PlayerEvent{

    private Translation|string|null $quitMessage;

    private string $reason;

    public function __construct(Player $player, Translation|string|null $quitMessage, string $reason){
        $this->player = $player;
        $this->quitMessage = $quitMessage;
        $this->reason = $reason;
    }

    public function getQuitMessage() : Translation|string|null{
        return $this->quitMessage;
    }

    public function setQuitMessage(Translation|string|null $quitMessage) : void{
        $this->quitMessage = $quitMessage;
    }

    /**
     * Why the connection closed, like "Connection closed" or the kick message.
     */
    public function getReason() : string{
        return $this->reason;
    }
}