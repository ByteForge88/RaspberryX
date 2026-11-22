<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandSender;

use raspberry\network\mcpe\protocol\AvailableCommandsPacket;

use const PHP_INT_MAX;

/**
 * The rest of the line, exactly as typed. It has to be the last argument.
 */
class RawTextArgument extends Argument{

    public function getNetworkType() : int{
        return AvailableCommandsPacket::ARG_TYPE_RAWTEXT;
    }

    public function getTypeName() : string{
        return "text";
    }

    public function getWordCount() : int{
        return PHP_INT_MAX;
    }

    public function accepts(string $text, CommandSender $sender) : bool{
        return $text !== "";
    }

    public function read(string $text, CommandSender $sender) : string{
        return $text;
    }
}