<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandOutput;
use raspberry\command\CommandSender;

use raspberry\plugin\PluginBase;

use raspberry\utils\TextFormat;

use raspberry\Server;

use function array_map;
use function count;
use function implode;

class PluginsCommand extends Command{

    public function __construct(){
        parent::__construct("plugins", "", "raspberry.command.plugins", ["pl"]);

        $this->setDescription($this->text("command.plugins.description"));
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        $plugins = Server::getInstance()->getPluginManager()->getPlugins();
        $names = array_map(fn(PluginBase $plugin) => ($plugin->isEnabled() ? TextFormat::GREEN : TextFormat::RED) . $plugin->getDescription()->getFullName() . TextFormat::RESET, $plugins);

        $output->successText($this->text("command.plugins.list", ["count" => count($plugins), "plugins" => implode(", ", $names)]));
    }
}