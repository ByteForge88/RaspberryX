<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandException;
use raspberry\command\CommandSender;

use raspberry\network\mcpe\protocol\AvailableCommandsPacket;

use raspberry\player\Player;

use raspberry\Server;

use function array_map;
use function implode;
use function preg_replace_callback;

/**
 * The rest of the line, like in /say. Selectors in it become the names they match, so "hi @a" is
 * "hi Alice, Bob". Selectors that match nobody, or have a wrong argument, stay as typed.
 */
class MessageArgument extends RawTextArgument{

    public function getNetworkType() : int{
        return AvailableCommandsPacket::ARG_TYPE_MESSAGE;
    }

    public function getTypeName() : string{
        return "message";
    }

    public function read(string $text, CommandSender $sender) : string{
        $commandMap = Server::getInstance()->getCommandMap();

        return preg_replace_callback('/@(initiator|a|e|p|r|s)(?![a-z0-9_])(\[[^\]]*\])?/i', function(array $match) use ($commandMap, $sender) : string{
            try{
                $players = $commandMap->selectPlayers($sender, $match[0]);
            }catch(CommandException){
                return $match[0];
            }

            return $players === [] ? $match[0] : implode(", ", array_map(fn(Player $player) => $player->getName(), $players));
        }, $text) ?? $text;
    }
}