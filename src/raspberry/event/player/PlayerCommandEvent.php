<?php

declare(strict_types=1);

namespace raspberry\event\player;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;

use raspberry\player\Player;

class PlayerCommandEvent extends PlayerEvent implements Cancellable{
    use CancellableTrait;

    private string $command;

    public function __construct(Player $player, string $command){
        $this->player = $player;
        $this->command = $command;
    }

    public function getCommand() : string{
        return $this->command;
    }

    public function setCommand(string $command) : void{
        $this->command = $command;
    }
}