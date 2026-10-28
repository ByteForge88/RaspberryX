<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\player\Player;

use raspberry\utils\TextFormat;

class TellCommand extends Command{

    public function __construct(){
        parent::__construct("tell");

        $this->setDescription($this->translate("command.tell.description"));
        $this->setPermission("raspberry.command.tell");
        $this->setAliases(["msg", "w"]);

        $this->setArgument(0, CommandParameter::target("target"));
        $this->setArgument(1, CommandParameter::message("message"));
    }

    public function onSent(CommandSender $sender, array $args) : void{
        /** @var list<Player> $players */
        $players = $args["target"];

        foreach($players as $player){
            $player->sendWhisper($sender->getName(), $args["message"]);
            $sender->sendMessage(TextFormat::GRAY . TextFormat::ITALIC . $this->translate("command.tell.sent", ["player" => $player->getName(), "message" => $args["message"]]));
        }
    }
}