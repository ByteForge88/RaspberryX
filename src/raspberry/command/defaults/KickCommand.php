<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\player\Player;

class KickCommand extends Command{

    public function __construct(){
        parent::__construct("kick");

        $this->setDescription($this->translate("command.kick.description"));
        $this->setPermission("raspberry.command.kick");

        $this->setArgument(0, CommandParameter::target("name"));
        $this->setArgument(1, CommandParameter::message("reason", true));
    }

    public function onSent(CommandSender $sender, array $args) : void{
        $reason = $args["reason"] ?? $this->translate("command.kick.default");

        /** @var list<Player> $players */
        $players = $args["name"];

        foreach($players as $player){
            $player->kick($reason);
            $sender->sendMessage($this->translate("command.kick.done", ["player" => $player->getName(), "reason" => $reason]));
        }
    }
}