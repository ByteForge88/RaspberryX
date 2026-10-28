<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\Server;

class SayCommand extends Command{

    public function __construct(){
        parent::__construct("say");

        $this->setDescription($this->translate("command.say.description"));
        $this->setPermission("raspberry.command.say");

        $this->setArgument(0, CommandParameter::message("message"));
    }

    public function onSent(CommandSender $sender, array $args) : void{
        Server::getInstance()->broadcastAnnouncement($sender->getName(), $args["message"]);
    }
}