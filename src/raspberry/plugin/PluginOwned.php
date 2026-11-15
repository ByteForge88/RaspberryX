<?php

declare(strict_types=1);

namespace raspberry\plugin;

interface PluginOwned{

    public function getOwningPlugin() : PluginBase;

}