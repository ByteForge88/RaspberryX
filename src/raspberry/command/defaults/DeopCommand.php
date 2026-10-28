<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandException;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\Server;

use raspberry\utils\TextFormat;

class DeopCommand extends Command{

    public function __construct(){
        parent::__construct("deop");

        $this->setDescription($this->translate("command.deop.description"));
        $this->setPermission("raspberry.command.deop");

        $this->setArgument(0, CommandParameter::string("player"));
    }

    public function onSent(CommandSender $sender, array $args) : void{
        $player = Server::getInstance()->getPlayerByPrefix($args["player"]);

        if($player !== null){
            if(!$player->isOp()){
                throw new CommandException($this->translate("command.deop.notOperator", ["player" => $player->getName()]));
            }

            $player->setOp(false);
            $player->sendMessage(TextFormat::GRAY . $this->translate("command.deop.notice"));
            $sender->sendMessage($this->translate("command.deop.done", ["player" => $player->getName()]));
            return;
        }

        $operators = Server::getInstance()->getOperatorList();
        $xuid = $operators->getXuidByName($args["player"]);

        if($xuid === null){
            throw new CommandException($this->translate("command.deop.notOperator", ["player" => $args["player"]]));
        }

        $name = $operators->getAll()[$xuid];
        $operators->remove($xuid);
        $sender->sendMessage($this->translate("command.deop.done", ["player" => $name]));
    }
}