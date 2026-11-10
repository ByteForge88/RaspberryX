<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandOutput;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\Server;

use function str_starts_with;

class DeopCommand extends Command{

    public function __construct(){
        parent::__construct("deop", "commands.deop.description", "raspberry.command.deop");

        $this->addOverload(CommandParameter::target("player", false, false));
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        $server = Server::getInstance();
        $target = $args["player"];
        $players = $server->getCommandMap()->selectPlayers($sender, $target);

        if($players === [] && !str_starts_with($target, "@")){
            $operators = $server->getOperatorList();
            $xuid = $operators->getXuidByName($target);

            if($xuid === null){
                $output->error("commands.deop.failed", [$target]);
                return;
            }

            $name = $operators->getAll()[$xuid];
            $operators->remove($xuid);
            $output->success("commands.deop.success", [$name]);
            return;
        }

        if($players === []){
            $output->error("commands.generic.noTargetMatch");
            return;
        }

        foreach($players as $player){
            if(!$player->isOp()){
                $output->error("commands.deop.failed", [$player->getName()]);
                continue;
            }

            $player->setOp(false);
            $player->sendTranslation("%commands.deop.message");
            $output->success("commands.deop.success", [$player->getName()]);
        }
    }
}