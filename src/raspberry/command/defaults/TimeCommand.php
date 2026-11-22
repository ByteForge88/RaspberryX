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

use raspberry\world\GameRules;
use raspberry\world\World;

use function array_keys;

class TimeCommand extends CommandBuilder{

    /** the one world clock there is until there are dimensions */
    public const string OVERWORLD_CLOCK = "minecraft:overworld";

    /** the time of day each marker stands for */
    public const array MARKERS = [
        "day" => World::TIME_DAY,
        "noon" => World::TIME_NOON,
        "sunset" => World::TIME_SUNSET,
        "night" => World::TIME_NIGHT,
        "midnight" => World::TIME_MIDNIGHT,
        "sunrise" => World::TIME_SUNRISE
    ];

    protected function configure() : void{
        $this->setName("time");
        $this->setDescription("commands.time.description");
        $this->setPermission("raspberry.command.time");

        $amount = new IntArgument("amount");
        $marker = new EnumArgument("timeMarkerName", new CommandEnum("ClockTimeMarkerName", array_keys(self::MARKERS), true));
        $occurrence = new EnumArgument("timeMarkerOccurrence", new CommandEnum("TimeMarkerOccurrence", ["next", "previous", "stay"]), true);
        $add = new KeywordArgument("mode", "TimeModeAdd", ["add"]);
        $set = new KeywordArgument("mode", "TimeModeSet", ["set"]);
        $query = new KeywordArgument("mode", "TimeModeQuery", ["query"]);
        $pause = new KeywordArgument("mode", "TimeModePause", ["pause"]);
        $resume = new KeywordArgument("mode", "TimeModeResume", ["resume"]);
        $of = new KeywordArgument("command", "TimeOfCommand", ["of"]);
        $clock = new EnumArgument("clockName", new CommandEnum("WorldClocks", [self::OVERWORLD_CLOCK]));

        $addTime = new ParameterBuilder(0);
        $addTime->addArgument(0, $add);
        $addTime->addArgument(1, $amount);
        $this->addParameterBuilder($addTime);

        $setTime = new ParameterBuilder(1);
        $setTime->addArgument(0, $set);
        $setTime->addArgument(1, $amount);
        $this->addParameterBuilder($setTime);

        $setMarker = new ParameterBuilder(2);
        $setMarker->addArgument(0, $set);
        $setMarker->addArgument(1, $marker);
        $setMarker->addArgument(2, $occurrence);
        $this->addParameterBuilder($setMarker);

        $queryTime = new ParameterBuilder(3);
        $queryTime->addArgument(0, $query);
        $queryTime->addArgument(1, new EnumArgument("time", new CommandEnum("TimeQuery2", ["time", "daytime", "gametime", "day"])));
        $this->addParameterBuilder($queryTime);

        $pauseTime = new ParameterBuilder(4);
        $pauseTime->addArgument(0, $pause);
        $this->addParameterBuilder($pauseTime);

        $resumeTime = new ParameterBuilder(5);
        $resumeTime->addArgument(0, $resume);
        $this->addParameterBuilder($resumeTime);

        $addClock = new ParameterBuilder(6);
        $addClock->addArgument(0, $of);
        $addClock->addArgument(1, $clock);
        $addClock->addArgument(2, $add);
        $addClock->addArgument(3, $amount);
        $this->addParameterBuilder($addClock);

        $setClock = new ParameterBuilder(7);
        $setClock->addArgument(0, $of);
        $setClock->addArgument(1, $clock);
        $setClock->addArgument(2, $set);
        $setClock->addArgument(3, $amount);
        $this->addParameterBuilder($setClock);

        $setClockMarker = new ParameterBuilder(8);
        $setClockMarker->addArgument(0, $of);
        $setClockMarker->addArgument(1, $clock);
        $setClockMarker->addArgument(2, $set);
        $setClockMarker->addArgument(3, $marker);
        $setClockMarker->addArgument(4, $occurrence);
        $this->addParameterBuilder($setClockMarker);

        $queryClock = new ParameterBuilder(9);
        $queryClock->addArgument(0, $of);
        $queryClock->addArgument(1, $clock);
        $queryClock->addArgument(2, $query);
        $queryClock->addArgument(3, new EnumArgument("clockQuery", new CommandEnum("ClockTimeQuery", ["time"])));
        $this->addParameterBuilder($queryClock);

        $pauseClock = new ParameterBuilder(10);
        $pauseClock->addArgument(0, $of);
        $pauseClock->addArgument(1, $clock);
        $pauseClock->addArgument(2, $pause);
        $this->addParameterBuilder($pauseClock);

        $resumeClock = new ParameterBuilder(11);
        $resumeClock->addArgument(0, $of);
        $resumeClock->addArgument(1, $clock);
        $resumeClock->addArgument(2, $resume);
        $this->addParameterBuilder($resumeClock);
    }

    public function onSent(CommandSender $sender, string $label, array $args) : void{
        $world = $sender instanceof Player ? $sender->getWorld() : Server::getInstance()->getWorldManager()->getDefaultWorld();
        $named = isset($args["clockName"]);
        $clock = self::OVERWORLD_CLOCK;

        switch($args["mode"]){
            case "add":
                $world->setTime($world->getTime() + $args["amount"]);

                if($named){
                    $this->success(new Translation("commands.time.clock.added", [$args["amount"], $clock, $world->getTime()]));
                }else{
                    $this->success(new Translation("commands.time.added", [$args["amount"]]));
                }
                break;
            case "set":
                $time = isset($args["timeMarkerName"]) ? self::findMarker($world, $args["timeMarkerName"], $args["timeMarkerOccurrence"] ?? "next", $clock) : $args["amount"];

                if($time < 0){
                    throw new CommandException("commands.time.clock.num.tooSmall", [0]);
                }

                $world->setTime($time);

                if($named){
                    $this->success(new Translation("commands.time.clock.set", [$clock, $time]));
                }else{
                    $this->success(new Translation("commands.time.set", [$time]));
                }
                break;
            case "query":
                match($args["time"] ?? $args["clockQuery"]){
                    "daytime" => $this->success(new Translation("commands.time.query.daytime", [$world->getTimeOfDay()])),
                    "gametime" => $this->success(new Translation("commands.time.query.gametime", [$world->getGameTime()])),
                    "day" => $this->success(new Translation("commands.time.query.day", [$world->getDay()])),
                    default => $this->success(new Translation("commands.time.clock.query.time", [$clock, $world->getTime()]))
                };
                break;
            case "pause":
            case "resume":
                $run = $args["mode"] === "resume";

                if($world->getGameRules()->getBool(GameRules::DO_DAYLIGHT_CYCLE) === $run){
                    throw new CommandException($run ? "commands.time.clock.resumed" : "commands.time.clock.paused", [$clock, $world->getTime()]);
                }

                $world->setGameRule(GameRules::DO_DAYLIGHT_CYCLE, $run);
                $this->success(new Translation($run ? "commands.time.clock.resumed" : "commands.time.clock.paused", [$clock, $world->getTime()]));
                break;
        }
    }

    private static function findMarker(World $world, string $marker, string $occurrence, string $clock) : int{
        $now = $world->getTime();
        $time = $world->getDay() * World::DAY_LENGTH + self::MARKERS[$marker];

        if($occurrence === "next" && $time <= $now){
            $time += World::DAY_LENGTH;
        }elseif($occurrence === "previous" && $time >= $now){
            $time -= World::DAY_LENGTH;

            if($time < 0){
                throw new CommandException("commands.time.clock.timemarker.previous.failed", [$marker, $clock]);
            }
        }

        return $time;
    }
}