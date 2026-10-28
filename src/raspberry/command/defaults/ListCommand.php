<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandSender;

use raspberry\player\Player;

use raspberry\Server;

use function array_map;
use function count;
use function implode;
use function sort;

use const SORT_FLAG_CASE;
use const SORT_STRING;

class ListCommand extends Command{

    public function __construct(){
        parent::__construct("list");

        $this->setDescription($this->translate("command.list.description"));
        $this->setPermission("raspberry.command.list");
    }

    public function onSent(CommandSender $sender, array $args) : void{
        $names = array_map(fn(Player $player) => $player->getName(), Server::getInstance()->getInGamePlayers());
        sort($names, SORT_STRING | SORT_FLAG_CASE);

        $sender->sendMessage($this->translate("command.list.players", [
            "count" => count($names),
            "max" => Server::getInstance()->getMaxPlayers()
        ]));

        if($names !== []){
            $sender->sendMessage(implode(", ", $names));
        }
    }
}