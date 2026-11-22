<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\CommandBuilder;
use raspberry\command\CommandException;
use raspberry\command\CommandSender;
use raspberry\command\ParameterBuilder;

use raspberry\command\argument\IntArgument;
use raspberry\command\argument\PostfixArgument;
use raspberry\command\argument\TargetArgument;

use raspberry\lang\Translation;

use function abs;

class XpCommand extends CommandBuilder{

    protected function configure() : void{
        $this->setName("xp");
        $this->setDescription("commands.xp.description");
        $this->setPermission("raspberry.command.xp");

        $builder = new ParameterBuilder(0);
        $builder->addArgument(0, new IntArgument("amount"));
        $builder->addArgument(0, new PostfixArgument("levels", "L"));
        $builder->addArgument(1, new TargetArgument("player", true));
        $this->addParameterBuilder($builder);
    }

    public function onSent(CommandSender $sender, string $label, array $args) : void{
        $players = $this->targetsOrSelf($sender, $args, "player");
        $levels = isset($args["levels"]);
        $amount = $args["levels"] ?? $args["amount"];

        if(!$levels && $amount < 0){
            throw new CommandException("commands.xp.failure.widthdrawXp");
        }

        foreach($players as $player){
            if(!$levels){
                $player->addXp($amount);
                $this->success(new Translation("commands.xp.success", [$amount, $player->getName()]));
            }elseif($amount >= 0){
                $player->addXpLevels($amount);
                $this->success(new Translation("commands.xp.success.levels", [$amount, $player->getName()]));
            }else{
                $player->takeXpLevels(abs($amount));
                $this->success(new Translation("commands.xp.success.negative.levels", [abs($amount), $player->getName()]));
            }
        }
    }
}