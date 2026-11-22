<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\CommandBuilder;
use raspberry\command\CommandSender;

use raspberry\lang\Translation;

use raspberry\player\Player;

use raspberry\Server;

use function array_map;
use function count;
use function implode;
use function sort;

use const SORT_FLAG_CASE;
use const SORT_STRING;

class ListCommand extends CommandBuilder{

    protected function configure() : void{
        $this->setName("list");
        $this->setDescription("commands.list.description");
        $this->setPermission("raspberry.command.list");
    }

    public function onSent(CommandSender $sender, string $label, array $args) : void{
        $server = Server::getInstance();
        $names = array_map(fn(Player $player) => $player->getName(), $server->getInGamePlayers());
        sort($names, SORT_STRING | SORT_FLAG_CASE);

        $this->success(new Translation("commands.players.list", [count($names), $server->getMaxPlayers()]));
        $this->success(new Translation("commands.players.list.names", [implode(", ", $names)]));
    }
}