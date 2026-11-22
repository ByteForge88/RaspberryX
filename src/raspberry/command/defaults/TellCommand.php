<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\CommandBuilder;
use raspberry\command\CommandException;
use raspberry\command\CommandSender;
use raspberry\command\ParameterBuilder;

use raspberry\command\argument\MessageArgument;
use raspberry\command\argument\TargetArgument;

use raspberry\lang\Translation;

use raspberry\player\Player;

class TellCommand extends CommandBuilder{

    protected function configure() : void{
        $this->setName("tell");
        $this->setDescription("commands.tell.description");
        $this->setPermission("raspberry.command.tell");
        $this->setAliases(["w", "msg"]);

        $builder = new ParameterBuilder(0);
        $builder->addArgument(0, new TargetArgument("target"));
        $builder->addArgument(1, new MessageArgument("message"));
        $this->addParameterBuilder($builder);
    }

    public function onSent(CommandSender $sender, string $label, array $args) : void{
        /** @var list<Player> $players */
        $players = $args["target"];
        $message = $args["message"];
        $sent = false;

        foreach($players as $player){
            if($player === $sender){
                continue;
            }

            $player->sendWhisper($sender->getName(), $message);
            $this->success(new Translation("commands.message.display.outgoing", [$player->getName(), $message]));
            $sent = true;
        }

        if(!$sent){
            throw new CommandException("commands.message.sameTarget");
        }
    }
}