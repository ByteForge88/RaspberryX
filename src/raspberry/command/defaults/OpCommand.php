<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\CommandBuilder;
use raspberry\command\CommandException;
use raspberry\command\CommandSender;
use raspberry\command\ParameterBuilder;

use raspberry\command\argument\TargetArgument;

use raspberry\lang\Translation;

use raspberry\Server;

class OpCommand extends CommandBuilder{

    protected function configure() : void{
        $this->setName("op");
        $this->setDescription("commands.op.description");
        $this->setPermission("raspberry.command.op");

        $builder = new ParameterBuilder(0);
        $builder->addArgument(0, new TargetArgument("player", false, false));
        $this->addParameterBuilder($builder);
    }

    public function onSent(CommandSender $sender, string $label, array $args) : void{
        $players = Server::getInstance()->getCommandMap()->selectPlayers($sender, $args["player"]);

        if($players === []){
            throw new CommandException("commands.generic.noTargetMatch");
        }

        foreach($players as $player){
            if($player->isOp()){
                $this->failure(new Translation("commands.op.failed", [$player->getName()]));
                continue;
            }

            $player->setOp(true);
            $player->sendTranslation("%commands.op.message");
            $this->success(new Translation("commands.op.success", [$player->getName()]));
        }
    }
}