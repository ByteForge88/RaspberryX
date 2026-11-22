<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandSender;

use raspberry\network\mcpe\protocol\AvailableCommandsPacket;

use InvalidArgumentException;

use function preg_match;
use function preg_quote;

/**
 * A whole number with a letter after it, like 5L for levels in /xp. The command gets the number.
 */
class PostfixArgument extends Argument{

    private string $postfix;

    public function __construct(string $name, string $postfix, bool $optional = false){
        if($postfix === ""){
            throw new InvalidArgumentException("A postfix cannot be empty");
        }

        parent::__construct($name, $optional);
        $this->postfix = $postfix;
    }

    public function getPostfix() : string{
        return $this->postfix;
    }

    public function getNetworkType() : int{
        return AvailableCommandsPacket::ARG_TYPE_INT;
    }

    public function getTypeName() : string{
        return "int";
    }

    public function accepts(string $text, CommandSender $sender) : bool{
        return preg_match('/^[+-]?\d{1,18}' . preg_quote($this->postfix, "/") . '$/Di', $text) === 1;
    }

    public function read(string $text, CommandSender $sender) : int{
        preg_match('/^([+-]?\d{1,18})/', $text, $match);
        return (int) $match[1];
    }
}