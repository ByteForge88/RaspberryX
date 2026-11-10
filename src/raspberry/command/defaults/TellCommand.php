<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandException;
use raspberry\command\CommandOutput;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\player\Player;

class TellCommand extends Command{

    public function __construct(){
        parent::__construct("tell", "commands.tell.description", "raspberry.command.tell", ["w", "msg"]);

        $this->addOverload(CommandParameter::target("target"), CommandParameter::message("message"));
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        /** @var list<Player> $players */
        $players = $args["target"];
        $message = $args["message"];
        $sent = false;

        foreach($players as $player){
            if($player === $sender){
                continue;
            }

            $player->sendWhisper($sender->getName(), $message);
            $output->success("commands.message.display.outgoing", [$player->getName(), $message]);
            $sent = true;
        }

        if(!$sent){
            throw new CommandException("commands.message.sameTarget");
        }
    }
}