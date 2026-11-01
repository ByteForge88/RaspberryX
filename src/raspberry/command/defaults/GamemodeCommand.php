<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandEnum;
use raspberry\command\CommandException;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\player\GameMode;
use raspberry\player\Player;

use raspberry\Server;

use function strtolower;

class GamemodeCommand extends Command{

    public function __construct(){
        parent::__construct("gamemode");

        $this->setDescription($this->translate("command.gamemode.description"));
        $this->setPermission("raspberry.command.gamemode");

        $modes = new CommandEnum("GameMode", ["survival", "creative", "adventure", "spectator", "default", "s", "c", "a", "d"]);

        $this->setArgument(0, CommandParameter::enum("gameMode", $modes));
        $this->setArgument(1, CommandParameter::target("player", true));

        $this->setArgument(0, CommandParameter::int("gameMode"), 1);
        $this->setArgument(1, CommandParameter::target("player", true), 1);
    }

    public function onSent(CommandSender $sender, array $args) : void{
        $mode = $this->parseGameMode((string) $args["gameMode"]);

        if(isset($args["player"])){
            /** @var list<Player> $players */
            $players = $args["player"];
        }elseif($sender instanceof Player){
            $players = [$sender];
        }else{
            throw new CommandException($this->translate("command.gamemode.noPlayer"));
        }

        $name = $this->translate($mode->getTranslationKey());

        foreach($players as $player){
            $player->setGameMode($mode);

            if($player === $sender){
                $sender->sendMessage($this->translate("command.gamemode.self", ["mode" => $name]));
            }else{
                $player->sendMessage($this->translate("command.gamemode.notice", ["mode" => $name]));
                $sender->sendMessage($this->translate("command.gamemode.other", ["player" => $player->getName(), "mode" => $name]));
            }
        }
    }

    private function parseGameMode(string $value) : GameMode{
        $value = strtolower($value);

        if($value === "d" || $value === "default" || $value === "5"){
            return Server::getInstance()->getDefaultGameMode();
        }

        return GameMode::fromString($value) ?? throw new CommandException($this->translate("command.gamemode.unknown", ["mode" => $value]));
    }
}