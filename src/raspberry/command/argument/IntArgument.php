<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandSender;

use raspberry\network\mcpe\protocol\AvailableCommandsPacket;

use function preg_match;

/**
 * A whole number, like 64 or -3.
 */
class IntArgument extends Argument{

    public const string PATTERN = '/^[+-]?\d{1,18}$/D';

    public function getNetworkType() : int{
        return AvailableCommandsPacket::ARG_TYPE_INT;
    }

    public function getTypeName() : string{
        return "int";
    }

    public function accepts(string $text, CommandSender $sender) : bool{
        return preg_match(self::PATTERN, $text) === 1;
    }

    public function read(string $text, CommandSender $sender) : int{
        return (int) $text;
    }
}