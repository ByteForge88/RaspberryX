<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\CommandBuilder;
use raspberry\command\CommandSender;

use raspberry\plugin\PluginBase;

use raspberry\utils\TextFormat;

use raspberry\Server;

use function array_map;
use function count;
use function implode;

class PluginsCommand extends CommandBuilder{

    protected function configure() : void{
        $this->setName("plugins");
        $this->setDescription($this->text("command.plugins.description"));
        $this->setPermission("raspberry.command.plugins");
        $this->setAliases(["pl"]);
    }

    public function onSent(CommandSender $sender, string $label, array $args) : void{
        $plugins = Server::getInstance()->getPluginManager()->getPlugins();
        $names = array_map(fn(PluginBase $plugin) => ($plugin->isEnabled() ? TextFormat::GREEN : TextFormat::RED) . $plugin->getDescription()->getFullName() . TextFormat::RESET, $plugins);

        $this->success($this->text("command.plugins.list", ["count" => count($plugins), "plugins" => implode(", ", $names)]));
    }
}