<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandException;
use raspberry\command\CommandSender;
use raspberry\command\CommandWords;
use raspberry\command\TargetSelector;

use raspberry\network\mcpe\protocol\AvailableCommandsPacket;

use raspberry\player\Player;

use raspberry\Server;

use function preg_match;
use function str_starts_with;

/**
 * A player name or a selector (@a, @p, @r, @s, @e, with arguments like @a[r=10]). The command
 * gets the list<Player> it matches, and a selector matching nobody stops the command. With $resolve
 * false it gets the text as typed instead, e.g. to look up a player who is offline.
 */
class TargetArgument extends Argument{

    private bool $resolve;

    public function __construct(string $name, bool $optional = false, bool $resolve = true){
        parent::__construct($name, $optional);
        $this->resolve = $resolve;
    }

    public function resolves() : bool{
        return $this->resolve;
    }

    public function getNetworkType() : int{
        return AvailableCommandsPacket::ARG_TYPE_TARGET;
    }

    public function getTypeName() : string{
        return "target";
    }

    public function accepts(string $text, CommandSender $sender) : bool{
        $text = CommandWords::unquote($text);

        if($text === "" || $text === "*"){
            return false;
        }

        return !str_starts_with($text, "@") || preg_match(TargetSelector::PATTERN, $text) === 1;
    }

    /**
     * @return list<Player>|string
     */
    public function read(string $text, CommandSender $sender) : array|string{
        $text = CommandWords::unquote($text);

        if(!$this->resolve){
            return $text;
        }

        $players = Server::getInstance()->getCommandMap()->selectPlayers($sender, $text);

        return $players !== [] ? $players : throw new CommandException("commands.generic.noTargetMatch");
    }
}