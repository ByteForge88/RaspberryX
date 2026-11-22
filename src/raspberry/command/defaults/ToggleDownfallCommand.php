<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\Server;

use raspberry\command\CommandBuilder;
use raspberry\command\CommandSender;

use raspberry\lang\Translation;

use raspberry\player\Player;

use raspberry\world\Weather;

use function mt_rand;

class ToggleDownfallCommand extends CommandBuilder{

    protected function configure() : void{
        $this->setName("toggledownfall");
        $this->setDescription("commands.downfall.description");
        $this->setPermission("raspberry.command.toggledownfall");
    }

    public function onSent(CommandSender $sender, string $label, array $args) : void{
        $world = $sender instanceof Player ? $sender->getWorld() : Server::getInstance()->getWorldManager()->getDefaultWorld();
        $weather = $world->getWeather();
        $duration = mt_rand(...Weather::COMMAND_DURATION);

        if($weather->isRaining()){
            $weather->setClear($duration);
        }else{
            $weather->setRain($duration);
        }

        $this->success(new Translation("commands.downfall.success"));
    }
}