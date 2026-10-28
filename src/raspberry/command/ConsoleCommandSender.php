<?php

declare(strict_types=1);

namespace raspberry\command;

use raspberry\permission\PermissibleTrait;
use raspberry\permission\PermissionLevel;
use raspberry\permission\PermissionManager;

use raspberry\utils\Logger;

class ConsoleCommandSender implements CommandSender{
    use PermissibleTrait;

    private Logger $logger;

    private PermissionManager $permissionManager;

    public function __construct(Logger $logger, PermissionManager $permissionManager){
        $this->logger = $logger;
        $this->permissionManager = $permissionManager;
    }

    public function getName() : string{
        return "Console";
    }

    public function sendMessage(string $message) : void{
        $this->logger->info($message);
    }

    public function getPermissionLevel() : PermissionLevel{
        return PermissionLevel::CONSOLE;
    }

    protected function getPermissionManager() : PermissionManager{
        return $this->permissionManager;
    }
}