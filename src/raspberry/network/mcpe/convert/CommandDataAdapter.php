<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\convert;

use raspberry\command\CommandEnum;
use raspberry\command\CommandMap;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\network\mcpe\protocol\AvailableCommandsPacket;
use raspberry\network\mcpe\protocol\types\command\CommandData;
use raspberry\network\mcpe\protocol\types\command\CommandEnumData;
use raspberry\network\mcpe\protocol\types\command\CommandOverloadData;
use raspberry\network\mcpe\protocol\types\command\CommandParameterData;

use function array_unique;
use function array_values;
use function count;

class CommandDataAdapter{

    public static function createAvailableCommands(CommandMap $commandMap, CommandSender $sender) : AvailableCommandsPacket{
        $packet = new AvailableCommandsPacket();

        /** @var array<string, int> $enumIndices */
        $enumIndices = [];
        /** @var array<string, int> $softEnumIndices */
        $softEnumIndices = [];
        /** @var array<string, int> $postfixIndices */
        $postfixIndices = [];

        foreach($commandMap->getCommands() as $command){
            if(!$command->testPermission($sender)){
                continue;
            }

            $data = new CommandData($command->getLabel(), $command->getDescription());

            if($command->getAliases() !== []){
                $aliases = array_values(array_unique([...$command->getAliases(), $command->getLabel()]));

                $data->aliasesEnumIndex = count($packet->enums);
                $packet->enums[] = new CommandEnumData($command->getLabel() . "Aliases", $aliases);
            }

            foreach($command->getOverloads() as $overload){
                $parameters = [];

                foreach($overload as $parameter){
                    $parameters[] = new CommandParameterData(
                        $parameter->getName(),
                        self::getParameterType($packet, $parameter, $enumIndices, $softEnumIndices, $postfixIndices),
                        $parameter->isOptional()
                    );
                }

                $data->overloads[] = new CommandOverloadData($parameters);
            }

            $packet->commands[] = $data;
        }

        return $packet;
    }

    /**
     * @param array<string, int> $enumIndices
     * @param array<string, int> $softEnumIndices
     * @param array<string, int> $postfixIndices
     */
    private static function getParameterType(AvailableCommandsPacket $packet, CommandParameter $parameter, array &$enumIndices, array &$softEnumIndices, array &$postfixIndices) : int{
        $postfix = $parameter->getPostfix();

        if($postfix !== null){
            if(!isset($postfixIndices[$postfix])){
                $postfixIndices[$postfix] = count($packet->postfixes);
                $packet->postfixes[] = $postfix;
            }

            return AvailableCommandsPacket::ARG_FLAG_POSTFIX | $postfixIndices[$postfix];
        }

        $enum = $parameter->getEnum();

        if($enum === null){
            return AvailableCommandsPacket::ARG_FLAG_VALID | $parameter->getType();
        }

        if($enum->isSoft()){
            return AvailableCommandsPacket::ARG_FLAG_VALID | AvailableCommandsPacket::ARG_FLAG_SOFT_ENUM | self::addEnum($packet->softEnums, $softEnumIndices, $enum);
        }

        return AvailableCommandsPacket::ARG_FLAG_VALID | AvailableCommandsPacket::ARG_FLAG_ENUM | self::addEnum($packet->enums, $enumIndices, $enum);
    }

    /**
     * @param list<CommandEnumData> $enums
     * @param array<string, int>    $indices
     */
    private static function addEnum(array &$enums, array &$indices, CommandEnum $enum) : int{
        $name = $enum->getName();

        if(!isset($indices[$name])){
            $indices[$name] = count($enums);
            $enums[] = new CommandEnumData($name, $enum->getValues());
        }

        return $indices[$name];
    }
}