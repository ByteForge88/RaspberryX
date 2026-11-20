<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandException;
use raspberry\command\CommandOutput;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use function abs;

class XpCommand extends Command{

    public function __construct(){
        parent::__construct("xp", "commands.xp.description", "raspberry.command.xp");

        $this->addOverload(CommandParameter::int("amount"), CommandParameter::target("player", true));
        $this->addOverload(CommandParameter::postfix("levels", "L"), CommandParameter::target("player", true));
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        $players = $this->playersOrSelf($sender, $args, "player");
        $levels = isset($args["levels"]);
        $amount = $args["levels"] ?? $args["amount"];

        if(!$levels && $amount < 0){
            throw new CommandException("commands.xp.failure.widthdrawXp");
        }

        foreach($players as $player){
            if(!$levels){
                $player->addXp($amount);
                $output->success("commands.xp.success", [$amount, $player->getName()]);
            }elseif($amount >= 0){
                $player->addXpLevels($amount);
                $output->success("commands.xp.success.levels", [$amount, $player->getName()]);
            }else{
                $player->takeXpLevels(abs($amount));
                $output->success("commands.xp.success.negative.levels", [abs($amount), $player->getName()]);
            }
        }
    }
}