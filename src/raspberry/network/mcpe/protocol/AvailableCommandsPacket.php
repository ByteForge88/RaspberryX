<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use raspberry\network\mcpe\protocol\types\command\ChainedSubCommandData;
use raspberry\network\mcpe\protocol\types\command\CommandData;
use raspberry\network\mcpe\protocol\types\command\CommandEnumConstraintData;
use raspberry\network\mcpe\protocol\types\command\CommandEnumData;
use raspberry\network\mcpe\protocol\types\command\CommandOverloadData;
use raspberry\network\mcpe\protocol\types\command\CommandParameterData;

use UnexpectedValueException;

use function count;
use function ord;
use function str_split;

/**
 * The commands a player can use, with their parameters, so the client can suggest and check them.
 */
class AvailableCommandsPacket implements Packet{

    /**
     * A plain parameter type is sent as ARG_FLAG_VALID | ARG_TYPE_*.
     */
    public const ARG_FLAG_VALID = 0x100000;

    public const ARG_TYPE_INT = 1;
    public const ARG_TYPE_FLOAT = 3;
    public const ARG_TYPE_VALUE = 4;
    public const ARG_TYPE_WILDCARD_INT = 5;
    public const ARG_TYPE_OPERATOR = 6;
    public const ARG_TYPE_COMPARE_OPERATOR = 7;
    public const ARG_TYPE_TARGET = 8;

    public const ARG_TYPE_WILDCARD_TARGET = 10;

    public const ARG_TYPE_FILEPATH = 17;

    public const ARG_TYPE_FULL_INTEGER_RANGE = 23;

    public const ARG_TYPE_EQUIPMENT_SLOT = 47;
    public const ARG_TYPE_STRING = 56;

    public const ARG_TYPE_INT_POSITION = 64;
    public const ARG_TYPE_POSITION = 65;

    public const ARG_TYPE_MESSAGE = 68;

    public const ARG_TYPE_RAWTEXT = 70;

    public const ARG_TYPE_JSON = 74;

    public const ARG_TYPE_BLOCK_STATES = 84;

    public const ARG_TYPE_COMMAND = 90;

    /**
     * Enums are composed as ARG_FLAG_ENUM | ARG_FLAG_VALID | (index into $enums).
     */
    public const ARG_FLAG_ENUM = 0x200000;

    /**
     * ARG_FLAG_POSTFIX | (index into $postfixes), used for /xp <level: int>L. Only for integer parameters.
     */
    public const ARG_FLAG_POSTFIX = 0x1000000;

    /**
     * Soft enums are composed as ARG_FLAG_SOFT_ENUM | ARG_FLAG_VALID | (index into $softEnums).
     */
    public const ARG_FLAG_SOFT_ENUM = 0x4000000;

    private const NO_ALIASES = 0xffffffff;

    /** @var list<CommandEnumData> enums whose values only change when the commands are sent again */
    public array $enums = [];

    /** @var list<string> */
    public array $chainedSubCommandValues = [];

    /** @var list<string> */
    public array $postfixes = [];

    /** @var list<ChainedSubCommandData> */
    public array $chainedSubCommands = [];

    /** @var list<CommandData> */
    public array $commands = [];

    /** @var list<CommandEnumData> enums that can change later with UpdateSoftEnumPacket */
    public array $softEnums = [];

    /** @var list<CommandEnumConstraintData> */
    public array $enumConstraints = [];

    public static function getPacketId() : int{
        return ProtocolPacketIds::AVAILABLE_COMMANDS_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $enumValues = [];
        $enumValueIndices = [];

        foreach($this->enums as $enum){
            foreach($enum->values as $value){
                if(!isset($enumValueIndices[$value])){
                    $enumValueIndices[$value] = count($enumValues);
                    $enumValues[] = $value;
                }
            }
        }

        self::writeStrings($writer, $enumValues);
        self::writeStrings($writer, $this->chainedSubCommandValues);
        self::writeStrings($writer, $this->postfixes);

        $writer->writeUnsignedVarInt(count($this->enums));

        foreach($this->enums as $enum){
            $writer->writeString($enum->name);
            $writer->writeUnsignedVarInt(count($enum->values));

            foreach($enum->values as $value){
                $writer->writeUnsignedInt($enumValueIndices[$value]);
            }
        }

        $writer->writeUnsignedVarInt(count($this->chainedSubCommands));

        foreach($this->chainedSubCommands as $subCommand){
            $writer->writeString($subCommand->name);
            $writer->writeUnsignedVarInt(count($subCommand->values));

            foreach($subCommand->values as [$index, $type]){
                $writer->writeUnsignedVarInt($index);
                $writer->writeUnsignedVarInt($type);
            }
        }

        $writer->writeUnsignedVarInt(count($this->commands));

        foreach($this->commands as $command){
            $this->writeCommand($writer, $command);
        }

        $writer->writeUnsignedVarInt(count($this->softEnums));

        foreach($this->softEnums as $enum){
            $writer->writeString($enum->name);
            self::writeStrings($writer, $enum->values);
        }

        $writer->writeUnsignedVarInt(count($this->enumConstraints));

        foreach($this->enumConstraints as $constraint){
            $writer->writeUnsignedInt($constraint->valueIndex);
            $writer->writeUnsignedInt($constraint->enumIndex);
            $writer->writeUnsignedVarInt(count($constraint->constraints));

            foreach($constraint->constraints as $value){
                $writer->writeByte($value);
            }
        }
    }

    public function decodePayload(BinaryReader $reader) : void{
        $enumValues = self::readStrings($reader);
        $this->chainedSubCommandValues = self::readStrings($reader);
        $this->postfixes = self::readStrings($reader);

        for($i = 0, $count = $reader->readUnsignedVarInt(); $i < $count; ++$i){
            $name = $reader->readString();
            $values = [];

            for($j = 0, $valueCount = $reader->readUnsignedVarInt(); $j < $valueCount; ++$j){
                $index = $reader->readUnsignedInt();
                $values[] = $enumValues[$index] ?? throw new UnexpectedValueException("Enum \"{$name}\" uses missing value {$index}");
            }

            $this->enums[] = new CommandEnumData($name, $values);
        }

        for($i = 0, $count = $reader->readUnsignedVarInt(); $i < $count; ++$i){
            $name = $reader->readString();
            $values = [];

            for($j = 0, $valueCount = $reader->readUnsignedVarInt(); $j < $valueCount; ++$j){
                $values[] = [$reader->readUnsignedVarInt(), $reader->readUnsignedVarInt()];
            }

            $this->chainedSubCommands[] = new ChainedSubCommandData($name, $values);
        }

        for($i = 0, $count = $reader->readUnsignedVarInt(); $i < $count; ++$i){
            $this->commands[] = $this->readCommand($reader);
        }

        for($i = 0, $count = $reader->readUnsignedVarInt(); $i < $count; ++$i){
            $this->softEnums[] = new CommandEnumData($reader->readString(), self::readStrings($reader));
        }

        for($i = 0, $count = $reader->readUnsignedVarInt(); $i < $count; ++$i){
            $valueIndex = $reader->readUnsignedInt();
            $enumIndex = $reader->readUnsignedInt();
            $constraints = [];

            foreach(str_split($reader->readString()) as $byte){
                $constraints[] = ord($byte);
            }

            $this->enumConstraints[] = new CommandEnumConstraintData($valueIndex, $enumIndex, $constraints === [""] ? [] : $constraints);
        }
    }

    private function writeCommand(BinaryWriter $writer, CommandData $command) : void{
        $writer->writeString($command->name);
        $writer->writeString($command->description);
        $writer->writeUnsignedShort($command->flags);
        $writer->writeString($command->permission);
        $writer->writeUnsignedInt($command->aliasesEnumIndex < 0 ? self::NO_ALIASES : $command->aliasesEnumIndex);

        $writer->writeUnsignedVarInt(count($command->chainedSubCommandIndices));

        foreach($command->chainedSubCommandIndices as $index){
            $writer->writeUnsignedInt($index);
        }

        $writer->writeUnsignedVarInt(count($command->overloads));

        foreach($command->overloads as $overload){
            $writer->writeBool($overload->chaining);
            $writer->writeUnsignedVarInt(count($overload->parameters));

            foreach($overload->parameters as $parameter){
                $writer->writeString($parameter->name);
                $writer->writeUnsignedInt($parameter->type);
                $writer->writeBool($parameter->optional);
                $writer->writeByte($parameter->options);
            }
        }
    }

    private function readCommand(BinaryReader $reader) : CommandData{
        $command = new CommandData($reader->readString(), $reader->readString());
        $command->flags = $reader->readUnsignedShort();
        $command->permission = $reader->readString();

        $aliases = $reader->readUnsignedInt();
        $command->aliasesEnumIndex = $aliases === self::NO_ALIASES ? -1 : $aliases;

        for($i = 0, $count = $reader->readUnsignedVarInt(); $i < $count; ++$i){
            $command->chainedSubCommandIndices[] = $reader->readUnsignedInt();
        }

        for($i = 0, $count = $reader->readUnsignedVarInt(); $i < $count; ++$i){
            $overload = new CommandOverloadData([]);
            $overload->chaining = $reader->readBool();

            for($j = 0, $parameterCount = $reader->readUnsignedVarInt(); $j < $parameterCount; ++$j){
                $parameter = new CommandParameterData($reader->readString(), $reader->readUnsignedInt(), $reader->readBool());
                $parameter->options = $reader->readByte();
                $overload->parameters[] = $parameter;
            }

            $command->overloads[] = $overload;
        }

        return $command;
    }

    /**
     * @param list<string> $strings
     */
    private static function writeStrings(BinaryWriter $writer, array $strings) : void{
        $writer->writeUnsignedVarInt(count($strings));

        foreach($strings as $string){
            $writer->writeString($string);
        }
    }

    /**
     * @return list<string>
     */
    private static function readStrings(BinaryReader $reader) : array{
        $strings = [];

        for($i = 0, $count = $reader->readUnsignedVarInt(); $i < $count; ++$i){
            $strings[] = $reader->readString();
        }

        return $strings;
    }
}