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

use function str_starts_with;

class DeopCommand extends CommandBuilder{

    protected function configure() : void{
        $this->setName("deop");
        $this->setDescription("commands.deop.description");
        $this->setPermission("raspberry.command.deop");

        $builder = new ParameterBuilder(0);
        $builder->addArgument(0, new TargetArgument("player", false, false));
        $this->addParameterBuilder($builder);
    }

    public function onSent(CommandSender $sender, string $label, array $args) : void{
        $server = Server::getInstance();
        $target = $args["player"];
        $players = $server->getCommandMap()->selectPlayers($sender, $target);

        if($players === [] && !str_starts_with($target, "@")){
            $operators = $server->getOperatorList();
            $xuid = $operators->getXuidByName($target) ?? throw new CommandException("commands.deop.failed", [$target]);
            $name = $operators->getAll()[$xuid];

            $operators->remove($xuid);
            $this->success(new Translation("commands.deop.success", [$name]));
            return;
        }

        if($players === []){
            throw new CommandException("commands.generic.noTargetMatch");
        }

        foreach($players as $player){
            if(!$player->isOp()){
                $this->failure(new Translation("commands.deop.failed", [$player->getName()]));
                continue;
            }

            $player->setOp(false);
            $player->sendTranslation("%commands.deop.message");
            $this->success(new Translation("commands.deop.success", [$player->getName()]));
        }
    }
}