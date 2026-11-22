<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\Server;

use raspberry\command\CommandBuilder;
use raspberry\command\CommandEnum;
use raspberry\command\CommandSender;
use raspberry\command\ParameterBuilder;

use raspberry\command\argument\BoolArgument;
use raspberry\command\argument\EnumArgument;
use raspberry\command\argument\IntArgument;

use raspberry\lang\Translation;

use raspberry\player\Player;

use raspberry\world\GameRules;

use function array_filter;
use function array_keys;
use function implode;
use function is_bool;
use function var_export;

use const ARRAY_FILTER_USE_KEY;

class GameruleCommand extends CommandBuilder{

    protected function configure() : void{
        $this->setName("gamerule");
        $this->setDescription("commands.gamerule.description");
        $this->setPermission("raspberry.command.gamerule");

        $bool = array_keys(array_filter(GameRules::DEFAULTS, fn(string $name) : bool => GameRules::isBoolRule($name), ARRAY_FILTER_USE_KEY));
        $int = array_keys(array_filter(GameRules::DEFAULTS, fn(string $name) : bool => GameRules::isIntRule($name), ARRAY_FILTER_USE_KEY));

        $this->addParameterBuilder(new ParameterBuilder(0));

        $boolRule = new ParameterBuilder(1);
        $boolRule->addArgument(0, new EnumArgument("rule", new CommandEnum("BoolGameRule", $bool)));
        $boolRule->addArgument(1, new BoolArgument("value", true));
        $this->addParameterBuilder($boolRule);

        $intRule = new ParameterBuilder(2);
        $intRule->addArgument(0, new EnumArgument("rule", new CommandEnum("IntGameRule", $int)));
        $intRule->addArgument(1, new IntArgument("value", true));
        $this->addParameterBuilder($intRule);
    }

    public function onSent(CommandSender $sender, string $label, array $args) : void{
        $world = $sender instanceof Player ? $sender->getWorld() : Server::getInstance()->getWorldManager()->getDefaultWorld();
        $rules = $world->getGameRules();

        if(!isset($args["rule"])){
            $list = [];

            foreach($rules->getAll() as $name => $value){
                $list[] = $name . " = " . self::show($value);
            }

            $this->success(implode(", ", $list));
            return;
        }

        $rule = $args["rule"];

        if(!isset($args["value"])){
            $this->success($rule . " = " . self::show($rules->get($rule) ?? false));
            return;
        }

        $world->setGameRule($rule, $args["value"]);
        $this->success(new Translation("commands.gamerule.success", [$rule, self::show($args["value"])]));
    }

    private static function show(bool|int $value) : string{
        return is_bool($value) ? var_export($value, true) : (string) $value;
    }
}