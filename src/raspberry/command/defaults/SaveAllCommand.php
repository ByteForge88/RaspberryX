<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\CommandBuilder;
use raspberry\command\CommandSender;

use raspberry\Server;

use function hrtime;
use function sprintf;

class SaveAllCommand extends CommandBuilder{

    protected function configure() : void{
        $this->setName("save-all");
        $this->setDescription($this->text("command.saveall.description"));
        $this->setPermission("raspberry.command.save-all");
    }

    public function onSent(CommandSender $sender, string $label, array $args) : void{
        $start = hrtime(true);
        $saved = Server::getInstance()->getWorldManager()->saveAll();

        $this->success($this->text("server.saved", [
            "count" => $saved,
            "time" => sprintf("%.1f", (hrtime(true) - $start) / 1e6)
        ]));
    }
}