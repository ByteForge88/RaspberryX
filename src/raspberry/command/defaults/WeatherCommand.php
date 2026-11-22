<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\Server;

use raspberry\command\CommandBuilder;
use raspberry\command\CommandEnum;
use raspberry\command\CommandException;
use raspberry\command\CommandSender;
use raspberry\command\ParameterBuilder;

use raspberry\command\argument\EnumArgument;
use raspberry\command\argument\IntArgument;
use raspberry\command\argument\KeywordArgument;

use raspberry\lang\Translation;

use raspberry\player\Player;

use raspberry\world\Weather;

use function mt_rand;

class WeatherCommand extends CommandBuilder{

    public const int MAX_DURATION = 1000000;

    protected function configure() : void{
        $this->setName("weather");
        $this->setDescription("commands.weather.description");
        $this->setPermission("raspberry.command.weather");

        $change = new ParameterBuilder(0);
        $change->addArgument(0, new EnumArgument("type", new CommandEnum("WeatherType", ["clear", "rain", "thunder"])));
        $change->addArgument(1, new IntArgument("duration", true));
        $this->addParameterBuilder($change);

        $query = new ParameterBuilder(1);
        $query->addArgument(0, new KeywordArgument("query", "WeatherQuery", ["query"]));
        $this->addParameterBuilder($query);
    }

    public function onSent(CommandSender $sender, string $label, array $args) : void{
        $world = $sender instanceof Player ? $sender->getWorld() : Server::getInstance()->getWorldManager()->getDefaultWorld();
        $weather = $world->getWeather();

        if(isset($args["query"])){
            $state = $weather->isThundering() ? "thunder" : ($weather->isRaining() ? "rain" : "clear");
            $this->success(new Translation("commands.weather.query", [new Translation("commands.weather.query." . $state)]));
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

        $this->success(new Translation("commands.weather." . $args["type"]));
    }
}