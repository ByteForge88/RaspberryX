<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\Server;

use raspberry\command\Command;
use raspberry\command\CommandOutput;
use raspberry\command\CommandSender;

use raspberry\player\Player;

use raspberry\world\Weather;

use function mt_rand;

class ToggleDownfallCommand extends Command{

    public function __construct(){
        parent::__construct("toggledownfall", "commands.downfall.description", "raspberry.command.toggledownfall");
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        $world = $sender instanceof Player ? $sender->getWorld() : Server::getInstance()->getWorldManager()->getDefaultWorld();
        $weather = $world->getWeather();
        $duration = mt_rand(...Weather::COMMAND_DURATION);

        if($weather->isRaining()){
            $weather->setClear($duration);
        }else{
            $weather->setRain($duration);
        }

        $output->success("commands.downfall.success");
    }
}