<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandOutput;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\Server;

class SayCommand extends Command{

    public function __construct(){
        parent::__construct("say", "commands.say.description", "raspberry.command.say");

        $this->addOverload(CommandParameter::message("message"));
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        Server::getInstance()->broadcastAnnouncement($sender->getName(), $args["message"]);
        $output->addSuccess();
    }
}