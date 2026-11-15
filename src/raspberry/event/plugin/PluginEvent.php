<?php

declare(strict_types=1);

namespace raspberry\event\plugin;

use raspberry\event\Event;

use raspberry\plugin\PluginBase;

abstract class PluginEvent extends Event{

    protected PluginBase $plugin;

    public function getPlugin() : PluginBase{
        return $this->plugin;
    }
}