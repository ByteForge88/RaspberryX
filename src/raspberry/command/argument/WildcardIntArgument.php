<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandSender;

use raspberry\network\mcpe\protocol\AvailableCommandsPacket;

use function preg_match;

/**
 * A whole number, or * for any. The command gets null for *.
 */
class WildcardIntArgument extends Argument{

    public function getNetworkType() : int{
        return AvailableCommandsPacket::ARG_TYPE_WILDCARD_INT;
    }

    public function getTypeName() : string{
        return "wildcard int";
    }

    public function accepts(string $text, CommandSender $sender) : bool{
        return $text === "*" || preg_match(IntArgument::PATTERN, $text) === 1;
    }

    public function read(string $text, CommandSender $sender) : ?int{
        return $text === "*" ? null : (int) $text;
    }
}