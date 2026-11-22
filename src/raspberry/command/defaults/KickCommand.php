<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\CommandBuilder;
use raspberry\command\CommandException;
use raspberry\command\CommandSender;
use raspberry\command\ParameterBuilder;

use raspberry\command\argument\MessageArgument;
use raspberry\command\argument\TargetArgument;

use raspberry\lang\Translation;

use raspberry\Server;

class KickCommand extends CommandBuilder{

    protected function configure() : void{
        $this->setName("kick");
        $this->setDescription("commands.kick.description");
        $this->setPermission("raspberry.command.kick");

        $builder = new ParameterBuilder(0);
        $builder->addArgument(0, new TargetArgument("name", false, false));
        $builder->addArgument(1, new MessageArgument("reason", true));
        $this->addParameterBuilder($builder);
    }

    public function onSent(CommandSender $sender, string $label, array $args) : void{
        $players = Server::getInstance()->getCommandMap()->selectPlayers($sender, $args["name"]);
        $reason = $args["reason"] ?? null;

        if($players === []){
            throw new CommandException("commands.kick.not.found", [$args["name"]]);
        }

        foreach($players as $player){
            if($player === $sender){
                $this->failure(new Translation("commands.kick.not.yourself"));
                continue;
            }

            if(!$player->kick($reason ?? $this->text("command.kick.default"))){
                continue;
            }

            if($reason === null){
                $this->success(new Translation("commands.kick.success", [$player->getName()]));
            }else{
                $this->success(new Translation("commands.kick.success.reason", [$player->getName(), $reason]));
            }
        }
    }
}