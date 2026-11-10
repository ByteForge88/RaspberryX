<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandOutput;
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
        parent::__construct("list", "commands.list.description", "raspberry.command.list");
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        $server = Server::getInstance();
        $names = array_map(fn(Player $player) => $player->getName(), $server->getInGamePlayers());
        sort($names, SORT_STRING | SORT_FLAG_CASE);

        $output->success("commands.players.list", [count($names), $server->getMaxPlayers()]);
        $output->success("commands.players.list.names", [implode(", ", $names)]);
    }
}