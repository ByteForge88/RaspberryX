<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandEnum;
use raspberry\command\CommandSender;

use raspberry\player\GameMode;

use raspberry\Server;

/**
 * A game mode by name: survival, creative, adventure, spectator or default, or s, c, a and d.
 */
class GameModeArgument extends EnumArgument{

    public function __construct(string $name, bool $optional = false){
        parent::__construct($name, CommandEnum::gameModes(), $optional);
    }

    public function read(string $text, CommandSender $sender) : GameMode{
        return match(parent::read($text, $sender)){
            "survival", "s" => GameMode::SURVIVAL,
            "creative", "c" => GameMode::CREATIVE,
            "adventure", "a" => GameMode::ADVENTURE,
            "spectator" => GameMode::SPECTATOR,
            default => Server::getInstance()->getDefaultGameMode()
        };
    }
}