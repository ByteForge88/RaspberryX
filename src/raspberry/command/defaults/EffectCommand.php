<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandEnum;
use raspberry\command\CommandException;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\entity\effect\Effect;
use raspberry\entity\effect\EffectInstance;

use raspberry\player\Player;

use function array_map;

class EffectCommand extends Command{

    public const MAX_SECONDS = 1000000;

    private const DEFAULT_SECONDS = 30;

    public function __construct(){
        parent::__construct("effect");

        $this->setDescription($this->translate("command.effect.description"));
        $this->setPermission("raspberry.command.effect");

        $effects = new CommandEnum("Effect", array_map(fn(Effect $effect) => $effect->getName(), Effect::cases()));

        $this->setArgument(0, CommandParameter::target("player"));
        $this->setArgument(1, CommandParameter::enum("clear", new CommandEnum("EffectClear", ["clear"])));

        $this->setArgument(0, CommandParameter::target("player"), 1);
        $this->setArgument(1, CommandParameter::enum("effect", $effects), 1);
        $this->setArgument(2, CommandParameter::int("seconds", true), 1);
        $this->setArgument(3, CommandParameter::int("amplifier", true), 1);
        $this->setArgument(4, CommandParameter::bool("hideParticles", true), 1);
    }

    public function onSent(CommandSender $sender, array $args) : void{
        /** @var list<Player> $players */
        $players = $args["player"];

        if(isset($args["clear"])){
            foreach($players as $player){
                $player->getEffects()->clear();
                $sender->sendMessage($this->translate("command.effect.cleared", ["player" => $player->getName()]));
            }

            return;
        }

        $effect = Effect::fromName((string) $args["effect"]) ?? throw new CommandException($this->translate("command.effect.unknown", ["effect" => $args["effect"]]));
        $seconds = $args["seconds"] ?? self::DEFAULT_SECONDS;
        $amplifier = $args["amplifier"] ?? 0;

        if($seconds < 0 || $seconds > self::MAX_SECONDS){
            throw new CommandException($this->translate("command.effect.seconds", ["max" => self::MAX_SECONDS]));
        }

        if($amplifier < 0 || $amplifier > 255){
            throw new CommandException($this->translate("command.effect.amplifier"));
        }

        foreach($players as $player){
            if($seconds === 0 && !$effect->isInstant()){
                $player->getEffects()->remove($effect);
                $sender->sendMessage($this->translate("command.effect.removed", ["effect" => $effect->getName(), "player" => $player->getName()]));
                continue;
            }

            $instance = new EffectInstance($effect, $seconds * 20, $amplifier, false, ($args["hideParticles"] ?? false) !== true);

            if(!$player->getEffects()->add($instance)){
                $sender->sendMessage($this->translate("command.effect.failed", ["effect" => $effect->getName(), "player" => $player->getName()]));
                continue;
            }

            $sender->sendMessage($this->translate("command.effect.success", [
                "effect" => $effect->getName(),
                "level" => $amplifier + 1,
                "player" => $player->getName(),
                "seconds" => $seconds
            ]));
        }
    }
}