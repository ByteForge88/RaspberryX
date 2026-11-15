<?php

declare(strict_types=1);

namespace raspberry\plugin;

use raspberry\command\Command;

abstract class PluginOwnedCommand extends Command implements PluginOwned{
    use PluginOwnedTrait;

    /**
     * @param list<string> $aliases
     */
    public function __construct(PluginBase $plugin, string $name, string $description = "", ?string $permission = null, array $aliases = []){
        parent::__construct($name, $description, $permission, $aliases);
        $this->owningPlugin = $plugin;
    }
}