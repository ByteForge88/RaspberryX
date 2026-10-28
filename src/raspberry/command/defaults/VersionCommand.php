<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandSender;

use raspberry\network\mcpe\protocol\ProtocolInfo;

use raspberry\Server;

class VersionCommand extends Command{

    public function __construct(){
        parent::__construct("version");

        $this->setDescription($this->translate("command.version.description"));
        $this->setPermission("raspberry.command.version");
        $this->setAliases(["ver", "about"]);
    }

    public function onSent(CommandSender $sender, array $args) : void{
        $sender->sendMessage($this->translate("command.version.info", [
            "name" => Server::NAME,
            "version" => Server::VERSION,
            "minecraft" => ProtocolInfo::MINECRAFT_VERSION,
            "protocol" => ProtocolInfo::CURRENT_PROTOCOL
        ]));
    }
}