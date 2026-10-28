<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandSender;

use raspberry\Server;

use function hrtime;
use function sprintf;

class SaveAllCommand extends Command{

    public function __construct(){
        parent::__construct("save-all");

        $this->setDescription($this->translate("command.saveall.description"));
        $this->setPermission("raspberry.command.save-all");
    }

    public function onSent(CommandSender $sender, array $args) : void{
        $start = hrtime(true);
        $saved = Server::getInstance()->getWorldManager()->saveAll();

        $sender->sendMessage($this->translate("server.saved", [
            "count" => $saved,
            "time" => sprintf("%.1f", (hrtime(true) - $start) / 1e6)
        ]));
    }
}