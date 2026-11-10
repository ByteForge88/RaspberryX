<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandEnum;
use raspberry\command\CommandException;
use raspberry\command\CommandOutput;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\entity\effect\Effect;
use raspberry\entity\effect\EffectInstance;

use raspberry\lang\Translation;

use raspberry\player\Player;

class EffectCommand extends Command{

    public const MAX_SECONDS = 1000000;

    public const MAX_AMPLIFIER = 255;

    private const DEFAULT_SECONDS = 30;

    public function __construct(){
        parent::__construct("effect", "commands.effect.description", "raspberry.command.effect");

        $effects = CommandEnum::effects();

        $this->addOverload(
            CommandParameter::target("player"),
            CommandParameter::enum("Mode", new CommandEnum("ClearEffects", ["clear"])),
            CommandParameter::enum("effect", $effects, true)
        );

        $this->addOverload(
            CommandParameter::target("player"),
            CommandParameter::enum("effect", $effects),
            CommandParameter::int("seconds", true),
            CommandParameter::int("amplifier", true),
            CommandParameter::bool("hideParticles", true)
        );

        $this->addOverload(
            CommandParameter::target("player"),
            CommandParameter::enum("effect", $effects),
            CommandParameter::enum("Mode", new CommandEnum("AddInfiniteEffect", ["infinite"])),
            CommandParameter::int("amplifier", true),
            CommandParameter::bool("hideParticles", true)
        );
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        /** @var list<Player> $players */
        $players = $args["player"];
        $effect = isset($args["effect"]) ? (Effect::fromName($args["effect"]) ?? throw new CommandException("commands.effect.notFound", [$args["effect"]])) : null;

        if(($args["Mode"] ?? null) === "clear"){
            $this->clear($output, $players, $effect);
            return;
        }

        /** @var Effect $effect */
        $infinite = ($args["Mode"] ?? null) === "infinite";
        $seconds = $args["seconds"] ?? self::DEFAULT_SECONDS;
        $amplifier = $args["amplifier"] ?? 0;

        if($seconds < 0){
            throw new CommandException("commands.generic.num.tooSmall", [$seconds, 0]);
        }

        if($seconds > self::MAX_SECONDS){
            throw new CommandException("commands.generic.num.tooBig", [$seconds, self::MAX_SECONDS]);
        }

        if($amplifier < 0){
            throw new CommandException("commands.generic.num.tooSmall", [$amplifier, 0]);
        }

        if($amplifier > self::MAX_AMPLIFIER){
            throw new CommandException("commands.generic.num.tooBig", [$amplifier, self::MAX_AMPLIFIER]);
        }

        $name = new Translation($effect->getTranslationKey());
        $visible = ($args["hideParticles"] ?? false) !== true;

        foreach($players as $player){
            if($seconds === 0 && !$infinite && !$effect->isInstant()){
                if($player->getEffects()->has($effect)){
                    $player->getEffects()->remove($effect);
                    $output->success("commands.effect.success.removed", [$name, $player->getName()]);
                }else{
                    $output->error("commands.effect.failure.notActive", [$name, $player->getName()]);
                }

                continue;
            }

            $player->getEffects()->add(new EffectInstance($effect, $infinite ? EffectInstance::INFINITE : $seconds * 20, $amplifier, false, $visible), true);

            if($infinite){
                $output->success("commands.effect.success.infinite", [$name, $amplifier, $player->getName()]);
            }else{
                $output->success("commands.effect.success", [$name, $amplifier, $player->getName(), $seconds]);
            }
        }
    }

    /**
     * @param list<Player> $players
     */
    private function clear(CommandOutput $output, array $players, ?Effect $effect) : void{
        foreach($players as $player){
            $effects = $player->getEffects();

            if($effect !== null){
                $name = new Translation($effect->getTranslationKey());

                if($effects->has($effect)){
                    $effects->remove($effect);
                    $output->success("commands.effect.success.removed", [$name, $player->getName()]);
                }else{
                    $output->error("commands.effect.failure.notActive", [$name, $player->getName()]);
                }
            }elseif($effects->all() !== []){
                $effects->clear();
                $output->success("commands.effect.success.removed.all", [$player->getName()]);
            }else{
                $output->error("commands.effect.failure.notActive.all", [$player->getName()]);
            }
        }
    }
}