<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandSender;

use raspberry\network\mcpe\protocol\AvailableCommandsPacket;

/**
 * Like a target, but * means every player.
 */
class WildcardTargetArgument extends TargetArgument{

    public function getNetworkType() : int{
        return AvailableCommandsPacket::ARG_TYPE_WILDCARD_TARGET;
    }

    public function accepts(string $text, CommandSender $sender) : bool{
        return $text === "*" || parent::accepts($text, $sender);
    }
}