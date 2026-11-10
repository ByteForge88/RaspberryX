<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandEnum;
use raspberry\command\CommandException;
use raspberry\command\CommandOutput;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\lang\Translation;

use raspberry\player\GameMode;

use raspberry\Server;

class GamemodeCommand extends Command{

    public function __construct(){
        parent::__construct("gamemode", "commands.gamemode.description", "raspberry.command.gamemode");

        $this->addOverload(CommandParameter::enum("gameMode", CommandEnum::gameModes()), CommandParameter::target("player", true));
        $this->addOverload(CommandParameter::int("gameMode"), CommandParameter::target("player", true));
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        $mode = self::toGameMode($args["gameMode"]);
        $players = $this->playersOrSelf($sender, $args, "player");

        foreach($players as $player){
            $player->setGameMode($mode);

            if($player === $sender){
                $output->success("commands.gamemode.success.self", [new Translation("createWorldScreen.gameMode." . $mode->getName())]);
            }else{
                $player->sendTranslation("%gameMode.changed", ["%gameMode." . $mode->getName()]);
                $output->success("commands.gamemode.success.other", [new Translation("createWorldScreen.gameMode." . $mode->getName()), $player->getName()]);
            }
        }
    }

    private static function toGameMode(string|int $value) : GameMode{
        return match($value){
            "default", "d", 5 => Server::getInstance()->getDefaultGameMode(),
            "survival", "s", 0 => GameMode::SURVIVAL,
            "creative", "c", 1 => GameMode::CREATIVE,
            "adventure", "a", 2 => GameMode::ADVENTURE,
            "spectator", 6 => GameMode::SPECTATOR,
            default => throw new CommandException("commands.gamemode.fail.invalid", [(string) $value])
        };
    }
}