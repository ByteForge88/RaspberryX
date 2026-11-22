<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\CommandBuilder;
use raspberry\command\CommandSender;

use raspberry\lang\Translation;

use raspberry\Server;

class StopCommand extends CommandBuilder{

    protected function configure() : void{
        $this->setName("stop");
        $this->setDescription("commands.stop.description");
        $this->setPermission("raspberry.command.stop");
    }

    public function onSent(CommandSender $sender, string $label, array $args) : void{
        $this->success(new Translation("commands.stop.start"));
        Server::getInstance()->stop();
    }
}