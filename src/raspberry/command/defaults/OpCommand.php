<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandException;
use raspberry\command\CommandOutput;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\Server;

class OpCommand extends Command{

    public function __construct(){
        parent::__construct("op", "commands.op.description", "raspberry.command.op");

        $this->addOverload(CommandParameter::target("player", false, false));
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        $players = Server::getInstance()->getCommandMap()->selectPlayers($sender, $args["player"]);

        if($players === []){
            throw new CommandException("commands.generic.noTargetMatch");
        }

        foreach($players as $player){
            if($player->isOp()){
                $output->error("commands.op.failed", [$player->getName()]);
                continue;
            }

            $player->setOp(true);
            $player->sendTranslation("%commands.op.message");
            $output->success("commands.op.success", [$player->getName()]);
        }
    }
}