<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandException;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\player\Player;

class SpawnpointCommand extends Command{

    public function __construct(){
        parent::__construct("spawnpoint");

        $this->setDescription($this->translate("command.spawnpoint.description"));
        $this->setPermission("raspberry.command.spawnpoint");

        $this->setArgument(0, CommandParameter::target("player", true));
        $this->setArgument(1, CommandParameter::position("spawnPos", true));
    }

    public function onSent(CommandSender $sender, array $args) : void{
        if(isset($args["player"])){
            /** @var list<Player> $players */
            $players = $args["player"];
        }elseif($sender instanceof Player){
            $players = [$sender];
        }else{
            throw new CommandException($this->translate("command.spawnpoint.noPlayer"));
        }

        foreach($players as $player){
            $position = $args["spawnPos"] ?? $player->getPosition();
            $world = isset($args["spawnPos"]) && $sender instanceof Player ? $sender->getWorld() : $player->getWorld();

            $player->setSpawnPosition($position, $world);

            $sender->sendMessage($this->translate("command.spawnpoint.done", [
                "player" => $player->getName(),
                "x" => $position->getFloorX(),
                "y" => $position->getFloorY(),
                "z" => $position->getFloorZ()
            ]));
        }
    }
}