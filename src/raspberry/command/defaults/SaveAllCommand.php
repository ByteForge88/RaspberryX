<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandOutput;
use raspberry\command\CommandSender;

use raspberry\Server;

use function hrtime;
use function sprintf;

class SaveAllCommand extends Command{

    public function __construct(){
        parent::__construct("save-all", "", "raspberry.command.save-all");

        $this->setDescription($this->text("command.saveall.description"));
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        $start = hrtime(true);
        $saved = Server::getInstance()->getWorldManager()->saveAll();

        $output->successText($this->text("server.saved", [
            "count" => $saved,
            "time" => sprintf("%.1f", (hrtime(true) - $start) / 1e6)
        ]));
    }
}