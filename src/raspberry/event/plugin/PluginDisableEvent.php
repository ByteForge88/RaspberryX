<?php

declare(strict_types=1);

namespace raspberry\event\plugin;

use raspberry\plugin\PluginBase;

/**
 * A plugin is about to be disabled.
 */
class PluginDisableEvent extends PluginEvent{

    public function __construct(PluginBase $plugin){
        $this->plugin = $plugin;
    }
}