<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\player\Player;

use raspberry\utils\TextFormat;

class OpCommand extends Command{

    public function __construct(){
        parent::__construct("op");

        $this->setDescription($this->translate("command.op.description"));
        $this->setPermission("raspberry.command.op");

        $this->setArgument(0, CommandParameter::target("player"));
    }

    public function onSent(CommandSender $sender, array $args) : void{
        /** @var list<Player> $players */
        $players = $args["player"];

        foreach($players as $player){
            if($player->isOp()){
                $sender->sendMessage(TextFormat::RED . $this->translate("command.op.already", ["player" => $player->getName()]));
                continue;
            }

            $player->setOp(true);
            $player->sendMessage(TextFormat::GRAY . $this->translate("command.op.notice"));
            $sender->sendMessage($this->translate("command.op.done", ["player" => $player->getName()]));
        }
    }
}