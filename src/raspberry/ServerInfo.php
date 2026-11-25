<?php

declare(strict_types=1);

namespace raspberry;

class ServerInfo{

    public const string NAME = "RaspberryX";

    public const string VERSION = "1.0.0-alpha1";

    public const string PLUGIN_API = self::VERSION;

    public const string LICENSE = "GNU Lesser General Public License v3.0";

    public const bool IS_DEVELOPMENT = true;

    private function __construct(){
    }

    public static function isDevelopmentBuild() : bool{
        if(self::IS_DEVELOPMENT){
            Server::getInstance()->getLogger()->warning("This is a development build, bugs may be present so please refer to a stable release.");
            return true;
        }

        return false;
    }
}