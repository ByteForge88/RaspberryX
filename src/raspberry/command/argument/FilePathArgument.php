<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandSender;
use raspberry\command\CommandWords;

use raspberry\network\mcpe\protocol\AvailableCommandsPacket;

/**
 * A path like the name of a function or a structure: folder/name.
 */
class FilePathArgument extends Argument{

    public function getNetworkType() : int{
        return AvailableCommandsPacket::ARG_TYPE_FILEPATH;
    }

    public function getTypeName() : string{
        return "filepath";
    }

    public function accepts(string $text, CommandSender $sender) : bool{
        return CommandWords::unquote($text) !== "";
    }

    public function read(string $text, CommandSender $sender) : string{
        return CommandWords::unquote($text);
    }
}