<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\convert;

use raspberry\command\CommandBuilder;
use raspberry\command\CommandMap;
use raspberry\command\CommandSender;

use raspberry\command\argument\Argument;

use raspberry\network\mcpe\protocol\AvailableCommandsPacket;
use raspberry\network\mcpe\protocol\types\command\CommandData;
use raspberry\network\mcpe\protocol\types\command\CommandEnumData;
use raspberry\network\mcpe\protocol\types\command\CommandOverloadData;
use raspberry\network\mcpe\protocol\types\command\CommandParameterData;

use function array_unique;
use function array_values;
use function count;
use function sort;

class CommandDataAdapter{

    public static function createAvailableCommands(CommandMap $commandMap, CommandSender $sender) : AvailableCommandsPacket{
        $packet = new AvailableCommandsPacket();
        $commands = [];
        $names = [];

        foreach($commandMap->getCommands() as $command){
            if($command->canUse($sender)){
                $commands[] = $command;

                foreach(self::getNames($commandMap, $command) as $name){
                    $names[] = $name;
                }
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        /** @var array<string, int> $enumIndices */
        $enumIndices = [];
        /** @var array<string, int> $softEnumIndices */
        $softEnumIndices = [];
        /** @var array<string, int> $postfixIndices */
        $postfixIndices = [];

        foreach($commands as $command){
            $data = new CommandData($command->getLabel(), $command->getDescription());

            $aliases = self::getNames($commandMap, $command);

            if(count($aliases) > 1){
                $data->aliasesEnumIndex = count($packet->enums);
                $packet->enums[] = new CommandEnumData($command->getLabel() . "CommandAliases", $aliases);
            }

            foreach($command->getOverloads($sender) as $overload){
                $parameters = [];

                foreach($overload as $argument){
                    $parameters[] = new CommandParameterData(
                        $argument->getName(),
                        self::getParameterType($packet, $argument, $names, $enumIndices, $softEnumIndices, $postfixIndices),
                        $argument->isOptional()
                    );
                }

                $data->overloads[] = new CommandOverloadData($parameters);
            }

            $packet->commands[] = $data;
        }

        return $packet;
    }

    /**
     * @return list<string>
     */
    private static function getNames(CommandMap $commandMap, CommandBuilder $command) : array{
        $names = [$command->getLabel()];

        foreach($command->getAliases() as $alias){
            if($alias !== $command->getLabel() && $commandMap->getCommand($alias) === $command){
                $names[] = $alias;
            }
        }

        return $names;
    }

    /**
     * @param list<string>       $commandNames
     * @param array<string, int> $enumIndices
     * @param array<string, int> $softEnumIndices
     * @param array<string, int> $postfixIndices
     */
    private static function getParameterType(AvailableCommandsPacket $packet, Argument $argument, array $commandNames, array &$enumIndices, array &$softEnumIndices, array &$postfixIndices) : int{
        $postfix = $argument->getPostfix();

        if($postfix !== null){
            if(!isset($postfixIndices[$postfix])){
                $postfixIndices[$postfix] = count($packet->postfixes);
                $packet->postfixes[] = $postfix;
            }

            return AvailableCommandsPacket::ARG_FLAG_POSTFIX | $postfixIndices[$postfix];
        }

        $enum = $argument->getEnum();

        if($enum === null){
            return AvailableCommandsPacket::ARG_FLAG_VALID | $argument->getNetworkType();
        }

        $values = $enum->isDynamic() ? $commandNames : $enum->getValues();

        if($enum->isSoft()){
            return AvailableCommandsPacket::ARG_FLAG_VALID | AvailableCommandsPacket::ARG_FLAG_SOFT_ENUM | self::addEnum($packet->softEnums, $softEnumIndices, $enum->getName(), $values);
        }

        return AvailableCommandsPacket::ARG_FLAG_VALID | AvailableCommandsPacket::ARG_FLAG_ENUM | self::addEnum($packet->enums, $enumIndices, $enum->getName(), $values);
    }

    /**
     * @param list<CommandEnumData> $enums
     * @param array<string, int>    $indices
     * @param list<string>          $values
     */
    private static function addEnum(array &$enums, array &$indices, string $name, array $values) : int{
        if(!isset($indices[$name])){
            $indices[$name] = count($enums);
            $enums[] = new CommandEnumData($name, $values);
        }

        return $indices[$name];
    }
}