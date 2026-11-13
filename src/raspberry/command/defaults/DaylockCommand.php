<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\Server;

use raspberry\command\Command;
use raspberry\command\CommandOutput;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\player\Player;

use raspberry\world\World;
use raspberry\world\GameRules;

class DaylockCommand extends Command{

    public function __construct(){
        parent::__construct("daylock", "commands.daylock.description", "raspberry.command.daylock", ["alwaysday"]);

        $this->addOverload(CommandParameter::bool("lock", true));
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        $world = $sender instanceof Player ? $sender->getWorld() : Server::getInstance()->getWorldManager()->getDefaultWorld();
        $lock = $args["lock"] ?? true;

        //like the setting, a lock holds it at day
        if($lock){
            $world->setTime($world->getDay() * World::DAY_LENGTH + World::TIME_DAY);
        }

        $world->setGameRule(GameRules::DO_DAYLIGHT_CYCLE, !$lock);
        $output->success($lock ? "commands.always.day.locked" : "commands.always.day.unlocked");
    }
}