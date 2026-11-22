<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\CommandBuilder;
use raspberry\command\CommandException;
use raspberry\command\CommandSender;
use raspberry\command\ParameterBuilder;

use raspberry\command\argument\PositionArgument;

use raspberry\lang\Translation;

use raspberry\math\Vector3;

use raspberry\player\Player;

use raspberry\Server;

class SetWorldSpawnCommand extends CommandBuilder{

    protected function configure() : void{
        $this->setName("setworldspawn");
        $this->setDescription("commands.setworldspawn.description");
        $this->setPermission("raspberry.command.setworldspawn");

        $builder = new ParameterBuilder(0);
        $builder->addArgument(0, new PositionArgument("spawnPoint", true));
        $this->addParameterBuilder($builder);
    }

    public function onSent(CommandSender $sender, string $label, array $args) : void{
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

        $this->success(new Translation("commands.setworldspawn.success", [$spawn->getFloorX(), $spawn->getFloorY(), $spawn->getFloorZ()]));
    }
}