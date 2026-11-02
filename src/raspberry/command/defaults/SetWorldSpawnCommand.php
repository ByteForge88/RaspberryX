<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandException;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\math\Vector3;

use raspberry\player\Player;

use raspberry\Server;

class SetWorldSpawnCommand extends Command{

    public function __construct(){
        parent::__construct("setworldspawn");

        $this->setDescription($this->translate("command.setworldspawn.description"));
        $this->setPermission("raspberry.command.setworldspawn");

        $this->setArgument(0, CommandParameter::position("spawnPoint", true));
    }

    public function onSent(CommandSender $sender, array $args) : void{
        if(isset($args["spawnPoint"])){
            $position = $args["spawnPoint"];
        }elseif($sender instanceof Player){
            $position = $sender->getPosition();
        }else{
            throw new CommandException($this->translate("command.setworldspawn.noPosition"));
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

        $sender->sendMessage($this->translate("command.setworldspawn.done", [
            "x" => $spawn->getFloorX(),
            "y" => $spawn->getFloorY(),
            "z" => $spawn->getFloorZ()
        ]));
    }
}