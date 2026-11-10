<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandOutput;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\player\Player;

use function array_map;
use function count;
use function implode;

class SpawnpointCommand extends Command{

    public function __construct(){
        parent::__construct("spawnpoint", "commands.spawnpoint.description", "raspberry.command.spawnpoint");

        $this->addOverload(CommandParameter::target("player", true), CommandParameter::position("spawnPos", true));
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        $players = $this->playersOrSelf($sender, $args, "player");

        foreach($players as $player){
            $position = $args["spawnPos"] ?? $player->getPosition();
            $world = isset($args["spawnPos"]) && $sender instanceof Player ? $sender->getWorld() : $player->getWorld();
            $player->setSpawnPosition($position, $world);
        }

        $position = $args["spawnPos"] ?? $players[0]->getPosition();
        $coordinates = [$position->getFloorX(), $position->getFloorY(), $position->getFloorZ()];

        if(count($players) === 1){
            $output->success("commands.spawnpoint.success.single", [$players[0]->getName(), ...$coordinates]);
        }elseif(isset($args["spawnPos"])){
            $output->success("commands.spawnpoint.success.multiple.specific", [implode(", ", array_map(fn(Player $player) => $player->getName(), $players)), ...$coordinates]);
        }else{
            $output->success("commands.spawnpoint.success.multiple.generic", [implode(", ", array_map(fn(Player $player) => $player->getName(), $players))]);
        }
    }
}