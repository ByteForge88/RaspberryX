<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\CommandBuilder;
use raspberry\command\CommandSender;

use raspberry\network\mcpe\protocol\ProtocolInfo;

use raspberry\ServerInfo;

class VersionCommand extends CommandBuilder{

    protected function configure() : void{
        $this->setName("version");
        $this->setDescription($this->text("command.version.description"));
        $this->setPermission("raspberry.command.version");
        $this->setAliases(["ver", "about"]);
    }

    public function onSent(CommandSender $sender, string $label, array $args) : void{
        $this->success($this->text("command.version.info", [
            "name" => ServerInfo::NAME,
            "version" => ServerInfo::VERSION,
            "minecraft" => ProtocolInfo::MINECRAFT_VERSION,
            "protocol" => ProtocolInfo::CURRENT_PROTOCOL
        ]));
    }
}