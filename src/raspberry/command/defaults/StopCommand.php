<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandOutput;
use raspberry\command\CommandSender;

use raspberry\Server;

class StopCommand extends Command{

    public function __construct(){
        parent::__construct("stop", "commands.stop.description", "raspberry.command.stop");
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        $output->success("commands.stop.start");
        Server::getInstance()->stop();
    }
}