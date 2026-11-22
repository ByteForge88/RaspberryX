<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\Server;

use raspberry\command\CommandBuilder;
use raspberry\command\CommandSender;
use raspberry\command\ParameterBuilder;

use raspberry\command\argument\BoolArgument;

use raspberry\lang\Translation;

use raspberry\player\Player;

use raspberry\world\World;
use raspberry\world\GameRules;

class DaylockCommand extends CommandBuilder{

    protected function configure() : void{
        $this->setName("daylock");
        $this->setDescription("commands.daylock.description");
        $this->setPermission("raspberry.command.daylock");
        $this->setAliases(["alwaysday"]);

        $builder = new ParameterBuilder(0);
        $builder->addArgument(0, new BoolArgument("lock", true));
        $this->addParameterBuilder($builder);
    }

    public function onSent(CommandSender $sender, string $label, array $args) : void{
        $world = $sender instanceof Player ? $sender->getWorld() : Server::getInstance()->getWorldManager()->getDefaultWorld();
        $lock = $args["lock"] ?? true;

        if($lock){
            $world->setTime($world->getDay() * World::DAY_LENGTH + World::TIME_DAY);
        }

        $world->setGameRule(GameRules::DO_DAYLIGHT_CYCLE, !$lock);
        $this->success(new Translation($lock ? "commands.always.day.locked" : "commands.always.day.unlocked"));
    }
}