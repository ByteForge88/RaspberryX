<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\Server;

use raspberry\command\Command;
use raspberry\command\CommandEnum;
use raspberry\command\CommandException;
use raspberry\command\CommandOutput;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\lang\Translation;

use raspberry\player\Player;

use raspberry\world\Weather;

use function mt_rand;

class WeatherCommand extends Command{

    public const MAX_DURATION = 1000000;

    public function __construct(){
        parent::__construct("weather", "commands.weather.description", "raspberry.command.weather");

        $this->addOverload(CommandParameter::enum("type", new CommandEnum("WeatherType", ["clear", "rain", "thunder"])), CommandParameter::int("duration", true));
        $this->addOverload(CommandParameter::enum("query", new CommandEnum("WeatherQuery", ["query"])));
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        $world = $sender instanceof Player ? $sender->getWorld() : Server::getInstance()->getWorldManager()->getDefaultWorld();
        $weather = $world->getWeather();

        if(isset($args["query"])){
            $state = $weather->isThundering() ? "thunder" : ($weather->isRaining() ? "rain" : "clear");
            $output->success("commands.weather.query", [new Translation("commands.weather.query." . $state)]);
            return;
        }

        $duration = $args["duration"] ?? 0;

        if($duration < 0){
            throw new CommandException("commands.generic.num.tooSmall", [$duration, 0]);
        }

        if($duration > self::MAX_DURATION){
            throw new CommandException("commands.generic.num.tooBig", [$duration, self::MAX_DURATION]);
        }

        if($duration === 0){
            $duration = mt_rand(...Weather::COMMAND_DURATION);
        }

        match($args["type"]){
            "clear" => $weather->setClear($duration),
            "rain" => $weather->setRain($duration),
            default => $weather->setThunder($duration)
        };

        $output->success("commands.weather." . $args["type"]);
    }
}