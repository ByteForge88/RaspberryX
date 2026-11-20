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

use raspberry\player\Player;

use raspberry\world\GameRules;
use raspberry\world\World;

use function array_keys;

class TimeCommand extends Command{

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

    public function __construct(){
        parent::__construct("time", "commands.time.description", "raspberry.command.time");

        $add = CommandParameter::enum("mode", new CommandEnum("TimeModeAdd", ["add"]));
        $set = CommandParameter::enum("mode", new CommandEnum("TimeModeSet", ["set"]));
        $query = CommandParameter::enum("mode", new CommandEnum("TimeModeQuery", ["query"]));
        $pause = CommandParameter::enum("mode", new CommandEnum("TimeModePause", ["pause"]));
        $resume = CommandParameter::enum("mode", new CommandEnum("TimeModeResume", ["resume"]));
        $marker = CommandParameter::enum("timeMarkerName", new CommandEnum("ClockTimeMarkerName", array_keys(self::MARKERS), true));
        $occurrence = CommandParameter::enum("timeMarkerOccurrence", new CommandEnum("TimeMarkerOccurrence", ["next", "previous", "stay"]), true);
        $of = CommandParameter::enum("command", new CommandEnum("TimeOfCommand", ["of"]));
        $clock = CommandParameter::enum("clockName", new CommandEnum("WorldClocks", [self::OVERWORLD_CLOCK]));

        $this->addOverload($add, CommandParameter::int("amount"));
        $this->addOverload($set, CommandParameter::int("amount"));
        $this->addOverload($set, $marker, $occurrence);
        $this->addOverload($query, CommandParameter::enum("time", new CommandEnum("TimeQuery2", ["time", "daytime", "gametime", "day"])));
        $this->addOverload($pause);
        $this->addOverload($resume);
        $this->addOverload($of, $clock, $add, CommandParameter::int("amount"));
        $this->addOverload($of, $clock, $set, CommandParameter::int("amount"));
        $this->addOverload($of, $clock, $set, $marker, $occurrence);
        $this->addOverload($of, $clock, $query, CommandParameter::enum("clockQuery", new CommandEnum("ClockTimeQuery", ["time"])));
        $this->addOverload($of, $clock, $pause);
        $this->addOverload($of, $clock, $resume);
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        $world = $sender instanceof Player ? $sender->getWorld() : Server::getInstance()->getWorldManager()->getDefaultWorld();
        $named = isset($args["clockName"]);
        $clock = self::OVERWORLD_CLOCK;

        switch($args["mode"]){
            case "add":
                $world->setTime($world->getTime() + $args["amount"]);

                if($named){
                    $output->success("commands.time.clock.added", [$args["amount"], $clock, $world->getTime()]);
                }else{
                    $output->success("commands.time.added", [$args["amount"]]);
                }
                break;
            case "set":
                $time = isset($args["timeMarkerName"]) ? self::findMarker($world, $args["timeMarkerName"], $args["timeMarkerOccurrence"] ?? "next", $clock) : $args["amount"];

                if($time < 0){
                    throw new CommandException("commands.time.clock.num.tooSmall", [0]);
                }

                $world->setTime($time);

                if($named){
                    $output->success("commands.time.clock.set", [$clock, $time]);
                }else{
                    $output->success("commands.time.set", [$time]);
                }
                break;
            case "query":
                match($args["time"] ?? $args["clockQuery"]){
                    "daytime" => $output->success("commands.time.query.daytime", [$world->getTimeOfDay()]),
                    "gametime" => $output->success("commands.time.query.gametime", [$world->getGameTime()]),
                    "day" => $output->success("commands.time.query.day", [$world->getDay()]),
                    default => $output->success("commands.time.clock.query.time", [$clock, $world->getTime()])
                };
                break;
            case "pause":
            case "resume":
                $run = $args["mode"] === "resume";

                if($world->getGameRules()->getBool(GameRules::DO_DAYLIGHT_CYCLE) === $run){
                    throw new CommandException($run ? "commands.time.clock.resumed" : "commands.time.clock.paused", [$clock, $world->getTime()]);
                }

                $world->setGameRule(GameRules::DO_DAYLIGHT_CYCLE, $run);
                $output->success($run ? "commands.time.clock.resumed" : "commands.time.clock.paused", [$clock, $world->getTime()]);
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