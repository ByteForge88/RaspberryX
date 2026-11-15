<?php

declare(strict_types=1);

namespace raspberry\event\plugin;

use raspberry\plugin\PluginBase;

/**
 * A plugin was enabled.
 */
class PluginEnableEvent extends PluginEvent{

    public function __construct(PluginBase $plugin){
        $this->plugin = $plugin;
    }
}