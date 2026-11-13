<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\Server;

use raspberry\command\Command;
use raspberry\command\CommandEnum;
use raspberry\command\CommandOutput;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\player\Player;

use raspberry\world\GameRules;

use function array_filter;
use function array_keys;
use function implode;
use function is_bool;
use function var_export;

use const ARRAY_FILTER_USE_KEY;

class GameruleCommand extends Command{

    public function __construct(){
        parent::__construct("gamerule", "commands.gamerule.description", "raspberry.command.gamerule");

        $bool = array_keys(array_filter(GameRules::DEFAULTS, fn(string $name) : bool => GameRules::isBoolRule($name), ARRAY_FILTER_USE_KEY));
        $int = array_keys(array_filter(GameRules::DEFAULTS, fn(string $name) : bool => GameRules::isIntRule($name), ARRAY_FILTER_USE_KEY));

        $this->addOverload();
        $this->addOverload(CommandParameter::enum("rule", new CommandEnum("BoolGameRule", $bool)), CommandParameter::bool("value", true));
        $this->addOverload(CommandParameter::enum("rule", new CommandEnum("IntGameRule", $int)), CommandParameter::int("value", true));
    }

    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        $world = $sender instanceof Player ? $sender->getWorld() : Server::getInstance()->getWorldManager()->getDefaultWorld();
        $rules = $world->getGameRules();

        if(!isset($args["rule"])){
            $list = [];

            foreach($rules->getAll() as $name => $value){
                $list[] = $name . " = " . self::show($value);
            }

            $output->successText(implode(", ", $list));
            return;
        }

        $rule = $args["rule"];

        if(!isset($args["value"])){
            $output->successText($rule . " = " . self::show($rules->get($rule) ?? false));
            return;
        }

        $world->setGameRule($rule, $args["value"]);
        $output->success("commands.gamerule.success", [$rule, self::show($args["value"])]);
    }

    private static function show(bool|int $value) : string{
        return is_bool($value) ? var_export($value, true) : (string) $value;
    }
}