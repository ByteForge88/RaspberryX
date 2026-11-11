<?php

declare(strict_types=1);

namespace raspberry\plugin;

/**
 * When a plugin is enabled: STARTUP before the default world is loaded, POSTWORLD after it.
 */
enum PluginLoadOrder : string{

    case STARTUP = "STARTUP";
    case POSTWORLD = "POSTWORLD";

}