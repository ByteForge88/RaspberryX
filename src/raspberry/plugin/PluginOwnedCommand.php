<?php

declare(strict_types=1);

namespace raspberry\plugin;

use raspberry\command\CommandBuilder;

abstract class PluginOwnedCommand extends CommandBuilder implements PluginOwned{
    use PluginOwnedTrait;

    public function __construct(PluginBase $plugin){
        $this->owningPlugin = $plugin;
        parent::__construct();
    }
}