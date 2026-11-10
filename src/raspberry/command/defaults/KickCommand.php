<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandOutput;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\Server;

class KickCommand extends Command{

    public function __construct(){
        parent::__construct("kick", "commands.kick.description", "raspberry.command.kick");

        $this->addOverload(CommandParameter::target("name", false, false), CommandParameter::message("reason", true));
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        $players = Server::getInstance()->getCommandMap()->selectPlayers($sender, $args["name"]);
        $reason = $args["reason"] ?? null;

        if($players === []){
            $output->error("commands.kick.not.found", [$args["name"]]);
            return;
        }

        foreach($players as $player){
            if($player === $sender){
                $output->error("commands.kick.not.yourself");
                continue;
            }

            $player->kick($reason ?? $this->text("command.kick.default"));

            if($reason === null){
                $output->success("commands.kick.success", [$player->getName()]);
            }else{
                $output->success("commands.kick.success.reason", [$player->getName(), $reason]);
            }
        }
    }
}