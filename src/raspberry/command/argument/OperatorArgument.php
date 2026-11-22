<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandSender;

use raspberry\network\mcpe\protocol\AvailableCommandsPacket;

use function in_array;

/**
 * How /scoreboard players operation changes a score: =, +=, -=, *=, /=, %=, <, > or ><.
 */
class OperatorArgument extends Argument{

    public const array OPERATORS = ["=", "+=", "-=", "*=", "/=", "%=", "<", ">", "><"];

    public function getNetworkType() : int{
        return AvailableCommandsPacket::ARG_TYPE_OPERATOR;
    }

    public function getTypeName() : string{
        return "operator";
    }

    public function accepts(string $text, CommandSender $sender) : bool{
        return in_array($text, self::OPERATORS, true);
    }

    public function read(string $text, CommandSender $sender) : string{
        return $text;
    }
}