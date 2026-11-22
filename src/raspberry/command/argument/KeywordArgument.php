<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandEnum;
use raspberry\command\CommandSender;

/**
 * A fixed word, like "facing" in /tp ~ ~ ~ facing @p, or "clear" in /effect @s clear. The command
 * gets the first of the words, whichever one was typed.
 */
class KeywordArgument extends EnumArgument{

    /**
     * @param string       $enumName how the client calls it, like "TeleportFacing"
     * @param list<string> $words    the keyword, and any others that mean the same
     */
    public function __construct(string $name, string $enumName, array $words, bool $optional = false){
        parent::__construct($name, new CommandEnum($enumName, $words), $optional);
    }

    public function read(string $text, CommandSender $sender) : string{
        return $this->enum->getValues()[0];
    }
}