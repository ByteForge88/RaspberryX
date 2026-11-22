<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandSender;

use raspberry\network\mcpe\protocol\AvailableCommandsPacket;

use function in_array;

/**
 * How two numbers are compared, like in /execute if score: <, <=, =, >= or >.
 */
class CompareOperatorArgument extends Argument{

    public const array OPERATORS = ["<", "<=", "=", ">=", ">"];

    public function getNetworkType() : int{
        return AvailableCommandsPacket::ARG_TYPE_COMPARE_OPERATOR;
    }

    public function getTypeName() : string{
        return "compare operator";
    }

    public function accepts(string $text, CommandSender $sender) : bool{
        return in_array($text, self::OPERATORS, true);
    }

    public function read(string $text, CommandSender $sender) : string{
        return $text;
    }
}