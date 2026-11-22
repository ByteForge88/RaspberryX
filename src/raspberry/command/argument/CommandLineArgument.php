<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandSender;

use raspberry\network\mcpe\protocol\AvailableCommandsPacket;

use function ltrim;

/**
 * Another command, for the rest of the line, like the end of /execute ... run say hi. The command
 * gets it without a slash, ready for Server::dispatchCommand().
 */
class CommandLineArgument extends RawTextArgument{

    public function getNetworkType() : int{
        return AvailableCommandsPacket::ARG_TYPE_COMMAND;
    }

    public function getTypeName() : string{
        return "command";
    }

    public function accepts(string $text, CommandSender $sender) : bool{
        return ltrim($text, "/") !== "";
    }

    public function read(string $text, CommandSender $sender) : string{
        return ltrim($text, "/");
    }
}