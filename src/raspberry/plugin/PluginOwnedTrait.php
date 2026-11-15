<?php

declare(strict_types=1);

namespace raspberry\plugin;

trait PluginOwnedTrait{

    private PluginBase $owningPlugin;

    public function getOwningPlugin() : PluginBase{
        return $this->owningPlugin;
    }
}