<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\CommandBuilder;
use raspberry\command\CommandException;
use raspberry\command\CommandSender;
use raspberry\command\ParameterBuilder;

use raspberry\command\argument\BoolArgument;
use raspberry\command\argument\EffectArgument;
use raspberry\command\argument\IntArgument;
use raspberry\command\argument\KeywordArgument;
use raspberry\command\argument\TargetArgument;

use raspberry\entity\effect\Effect;
use raspberry\entity\effect\EffectInstance;

use raspberry\lang\Translation;

use raspberry\player\Player;

class EffectCommand extends CommandBuilder{

    public const int MAX_SECONDS = 1000000;

    public const int MAX_AMPLIFIER = 255;

    private const int DEFAULT_SECONDS = 30;

    protected function configure() : void{
        $this->setName("effect");
        $this->setDescription("commands.effect.description");
        $this->setPermission("raspberry.command.effect");

        $clear = new ParameterBuilder(0);
        $clear->addArgument(0, new TargetArgument("player"));
        $clear->addArgument(1, new KeywordArgument("Mode", "ClearEffects", ["clear"]));
        $clear->addArgument(2, new EffectArgument("effect", true));
        $this->addParameterBuilder($clear);

        $give = new ParameterBuilder(1);
        $give->addArgument(0, new TargetArgument("player"));
        $give->addArgument(1, new EffectArgument("effect"));
        $give->addArgument(2, new IntArgument("seconds", true));
        $give->addArgument(3, new IntArgument("amplifier", true));
        $give->addArgument(4, new BoolArgument("hideParticles", true));
        $this->addParameterBuilder($give);

        $infinite = new ParameterBuilder(2);
        $infinite->addArgument(0, new TargetArgument("player"));
        $infinite->addArgument(1, new EffectArgument("effect"));
        $infinite->addArgument(2, new KeywordArgument("Mode", "AddInfiniteEffect", ["infinite"]));
        $infinite->addArgument(3, new IntArgument("amplifier", true));
        $infinite->addArgument(4, new BoolArgument("hideParticles", true));
        $this->addParameterBuilder($infinite);
    }

    public function onSent(CommandSender $sender, string $label, array $args) : void{
        /** @var list<Player> $players */
        $players = $args["player"];
        /** @var Effect|null $effect */
        $effect = $args["effect"] ?? null;

        if(($args["Mode"] ?? null) === "clear"){
            $this->clear($players, $effect);
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
                    $this->success(new Translation("commands.effect.success.removed", [$name, $player->getName()]));
                }else{
                    $this->failure(new Translation("commands.effect.failure.notActive", [$name, $player->getName()]));
                }

                continue;
            }

            $player->getEffects()->add(new EffectInstance($effect, $infinite ? EffectInstance::INFINITE : $seconds * 20, $amplifier, false, $visible), true);

            if($infinite){
                $this->success(new Translation("commands.effect.success.infinite", [$name, $amplifier, $player->getName()]));
            }else{
                $this->success(new Translation("commands.effect.success", [$name, $amplifier, $player->getName(), $seconds]));
            }
        }
    }

    /**
     * @param list<Player> $players
     */
    private function clear(array $players, ?Effect $effect) : void{
        foreach($players as $player){
            $effects = $player->getEffects();

            if($effect !== null){
                $name = new Translation($effect->getTranslationKey());

                if($effects->has($effect)){
                    $effects->remove($effect);
                    $this->success(new Translation("commands.effect.success.removed", [$name, $player->getName()]));
                }else{
                    $this->failure(new Translation("commands.effect.failure.notActive", [$name, $player->getName()]));
                }
            }elseif($effects->all() !== []){
                $effects->clear();
                $this->success(new Translation("commands.effect.success.removed.all", [$player->getName()]));
            }else{
                $this->failure(new Translation("commands.effect.failure.notActive.all", [$player->getName()]));
            }
        }
    }
}