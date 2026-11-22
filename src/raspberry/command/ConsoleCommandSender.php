<?php

declare(strict_types=1);

namespace raspberry\command;

use raspberry\lang\Language;
use raspberry\lang\Translation;

use raspberry\permission\PermissibleTrait;
use raspberry\permission\PermissionLevel;
use raspberry\permission\PermissionManager;

use raspberry\utils\Logger;
use raspberry\utils\TextFormat;

class ConsoleCommandSender implements CommandSender{
    use PermissibleTrait;

    private Logger $logger;

    private PermissionManager $permissionManager;

    private Language $language;

    public function __construct(Logger $logger, PermissionManager $permissionManager, Language $language){
        $this->logger = $logger;
        $this->permissionManager = $permissionManager;
        $this->language = $language;
    }

    public function getName() : string{
        return "Console";
    }

    public function sendMessage(string $message) : void{
        $this->logger->info($message);
    }

    public function sendCommandFeedback(CommandFeedback $feedback) : void{
        foreach($feedback->getMessages() as [$message, $success]){
            $this->logger->info(($success ? "" : TextFormat::RED) . ($message instanceof Translation ? $this->language->translateObject($message) : $message));
        }
    }

    public function getPermissionLevel() : PermissionLevel{
        return PermissionLevel::CONSOLE;
    }

    protected function getPermissionManager() : PermissionManager{
        return $this->permissionManager;
    }
}