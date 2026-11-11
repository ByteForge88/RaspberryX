<?php

declare(strict_types=1);

namespace raspberry\plugin;

use raspberry\command\CommandSender;

interface CommandExecutor{

    /**
     * @param list<string> $args
     *
     * @return bool
     */
    public function onCommand(CommandSender $sender, PluginCommand $command, array $args) : bool;

}