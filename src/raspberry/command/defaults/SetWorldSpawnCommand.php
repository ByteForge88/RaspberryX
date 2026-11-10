<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandException;
use raspberry\command\CommandOutput;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\math\Vector3;

use raspberry\player\Player;

use raspberry\Server;

class SetWorldSpawnCommand extends Command{

    public function __construct(){
        parent::__construct("setworldspawn", "commands.setworldspawn.description", "raspberry.command.setworldspawn");

        $this->addOverload(CommandParameter::position("spawnPoint", true));
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        if(isset($args["spawnPoint"])){
            $position = $args["spawnPoint"];
        }elseif($sender instanceof Player){
            $position = $sender->getPosition();
        }else{
            throw CommandException::text($this->text("command.setworldspawn.noPosition"));
        }

        $server = Server::getInstance();
        $world = $sender instanceof Player ? $sender->getWorld() : $server->getWorldManager()->getDefaultWorld();
        $spawn = new Vector3($position->getFloorX() + 0.5, (float) $position->getFloorY(), $position->getFloorZ() + 0.5);
        $world->setSpawnPosition($spawn);

        foreach($server->getInGamePlayers() as $player){
            if($player->getWorld() === $world){
                $player->getNetworkSession()->sendSpawnPositions();
            }
        }

        $output->success("commands.setworldspawn.success", [$spawn->getFloorX(), $spawn->getFloorY(), $spawn->getFloorZ()]);
    }
}