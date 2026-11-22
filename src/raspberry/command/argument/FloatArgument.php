<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandSender;

use raspberry\network\mcpe\protocol\AvailableCommandsPacket;

use function preg_match;

/**
 * A number that may have a point, like 1.5, .5 or 3.
 */
class FloatArgument extends Argument{

    public const string PATTERN = '/^[+-]?(\d{1,18}(\.\d*)?|\.\d+)$/D';

    public function getNetworkType() : int{
        return AvailableCommandsPacket::ARG_TYPE_FLOAT;
    }

    public function getTypeName() : string{
        return "float";
    }

    public function accepts(string $text, CommandSender $sender) : bool{
        return preg_match(self::PATTERN, $text) === 1;
    }

    public function read(string $text, CommandSender $sender) : float{
        return (float) $text;
    }
}