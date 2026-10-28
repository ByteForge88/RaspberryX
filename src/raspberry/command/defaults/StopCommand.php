<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandSender;

use raspberry\Server;

class StopCommand extends Command{

    public function __construct(){
        parent::__construct("stop");

        $this->setDescription($this->translate("command.stop.description"));
        $this->setPermission("raspberry.command.stop");
    }

    public function onSent(CommandSender $sender, array $args) : void{
        Server::getInstance()->stop();
    }
}