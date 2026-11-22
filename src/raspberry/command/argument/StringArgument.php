<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandSender;
use raspberry\command\CommandWords;

use raspberry\network\mcpe\protocol\AvailableCommandsPacket;

/**
 * One word, or "words in quotes". The command gets it without the quotes.
 */
class StringArgument extends Argument{

    public function getNetworkType() : int{
        return AvailableCommandsPacket::ARG_TYPE_STRING;
    }

    public function getTypeName() : string{
        return "string";
    }

    public function accepts(string $text, CommandSender $sender) : bool{
        return CommandWords::isClosed($text);
    }

    public function read(string $text, CommandSender $sender) : string{
        return CommandWords::unquote($text);
    }
}