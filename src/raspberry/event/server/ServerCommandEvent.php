<?php

declare(strict_types=1);

namespace raspberry\event\server;

use raspberry\command\CommandSender;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;
use raspberry\event\Event;

/**
 * A command was typed in the console. Change it with setCommand(), it has no / in front.
 */
class ServerCommandEvent extends Event implements Cancellable{
    use CancellableTrait;

    private CommandSender $sender;

    private string $command;

    public function __construct(CommandSender $sender, string $command){
        $this->sender = $sender;
        $this->command = $command;
    }

    public function getSender() : CommandSender{
        return $this->sender;
    }

    public function getCommand() : string{
        return $this->command;
    }

    public function setCommand(string $command) : void{
        $this->command = $command;
    }
}