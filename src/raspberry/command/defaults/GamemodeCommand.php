<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\CommandBuilder;
use raspberry\command\CommandException;
use raspberry\command\CommandSender;
use raspberry\command\ParameterBuilder;

use raspberry\command\argument\GameModeArgument;
use raspberry\command\argument\IntArgument;
use raspberry\command\argument\TargetArgument;

use raspberry\lang\Translation;

use raspberry\player\GameMode;

use raspberry\Server;

class GamemodeCommand extends CommandBuilder{

    protected function configure() : void{
        $this->setName("gamemode");
        $this->setDescription("commands.gamemode.description");
        $this->setPermission("raspberry.command.gamemode");

        $builder = new ParameterBuilder(0);
        $builder->addArgument(0, new GameModeArgument("gameMode"));
        $builder->addArgument(0, new IntArgument("gameMode"));
        $builder->addArgument(1, new TargetArgument("player", true));
        $this->addParameterBuilder($builder);
    }

    public function onSent(CommandSender $sender, string $label, array $args) : void{
        $mode = $args["gameMode"] instanceof GameMode ? $args["gameMode"] : self::fromNumber($args["gameMode"]);
        $name = new Translation("createWorldScreen.gameMode." . $mode->getName());

        foreach($this->targetsOrSelf($sender, $args, "player") as $player){
            $player->setGameMode($mode);

            if($player === $sender){
                $this->success(new Translation("commands.gamemode.success.self", [$name]));
            }else{
                $player->sendTranslation("%gameMode.changed", ["%gameMode." . $mode->getName()]);
                $this->success(new Translation("commands.gamemode.success.other", [$name, $player->getName()]));
            }
        }
    }

    private static function fromNumber(int $number) : GameMode{
        return match($number){
            5 => Server::getInstance()->getDefaultGameMode(),
            0, 1, 2, 6 => GameMode::from($number),
            default => throw new CommandException("commands.gamemode.fail.invalid", [(string) $number])
        };
    }
}