<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\CommandBuilder;
use raspberry\command\CommandSender;
use raspberry\command\ParameterBuilder;

use raspberry\command\argument\PositionArgument;
use raspberry\command\argument\TargetArgument;

use raspberry\lang\Translation;

use raspberry\player\Player;

use function array_map;
use function count;
use function implode;

class SpawnpointCommand extends CommandBuilder{

    protected function configure() : void{
        $this->setName("spawnpoint");
        $this->setDescription("commands.spawnpoint.description");
        $this->setPermission("raspberry.command.spawnpoint");

        $builder = new ParameterBuilder(0);
        $builder->addArgument(0, new TargetArgument("player", true));
        $builder->addArgument(1, new PositionArgument("spawnPos", true));
        $this->addParameterBuilder($builder);
    }

    public function onSent(CommandSender $sender, string $label, array $args) : void{
        $players = $this->targetsOrSelf($sender, $args, "player");

        foreach($players as $player){
            $position = $args["spawnPos"] ?? $player->getPosition();
            $world = isset($args["spawnPos"]) && $sender instanceof Player ? $sender->getWorld() : $player->getWorld();
            $player->setSpawnPosition($position, $world);
        }

        $position = $args["spawnPos"] ?? $players[0]->getPosition();
        $coordinates = [$position->getFloorX(), $position->getFloorY(), $position->getFloorZ()];
        $names = implode(", ", array_map(fn(Player $player) => $player->getName(), $players));

        if(count($players) === 1){
            $this->success(new Translation("commands.spawnpoint.success.single", [$players[0]->getName(), ...$coordinates]));
        }elseif(isset($args["spawnPos"])){
            $this->success(new Translation("commands.spawnpoint.success.multiple.specific", [$names, ...$coordinates]));
        }else{
            $this->success(new Translation("commands.spawnpoint.success.multiple.generic", [$names]));
        }
    }
}