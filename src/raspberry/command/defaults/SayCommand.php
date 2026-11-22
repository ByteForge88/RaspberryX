<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\CommandBuilder;
use raspberry\command\CommandSender;
use raspberry\command\ParameterBuilder;

use raspberry\command\argument\MessageArgument;

use raspberry\Server;

class SayCommand extends CommandBuilder{

    protected function configure() : void{
        $this->setName("say");
        $this->setDescription("commands.say.description");
        $this->setPermission("raspberry.command.say");

        $builder = new ParameterBuilder(0);
        $builder->addArgument(0, new MessageArgument("message"));
        $this->addParameterBuilder($builder);
    }

    public function onSent(CommandSender $sender, string $label, array $args) : void{
        Server::getInstance()->broadcastAnnouncement($sender->getName(), $args["message"]);
    }
}