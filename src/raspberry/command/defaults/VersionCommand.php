<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandOutput;
use raspberry\command\CommandSender;

use raspberry\network\mcpe\protocol\ProtocolInfo;

use raspberry\ServerInfo;

class VersionCommand extends Command{

    public function __construct(){
        parent::__construct("version", "", "raspberry.command.version", ["ver", "about"]);

        $this->setDescription($this->text("command.version.description"));
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        $output->successText($this->text("command.version.info", [
            "name" => ServerInfo::NAME,
            "version" => ServerInfo::VERSION,
            "minecraft" => ProtocolInfo::MINECRAFT_VERSION,
            "protocol" => ProtocolInfo::CURRENT_PROTOCOL
        ]));
    }
}