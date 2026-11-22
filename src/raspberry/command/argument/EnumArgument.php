<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandEnum;
use raspberry\command\CommandSender;
use raspberry\command\CommandWords;

/**
 * One of the values of an enum, ignoring case. The command gets the value as written in the
 * enum. A soft enum can get new values while players are online, see Server::updateSoftEnum().
 */
class EnumArgument extends Argument{

    protected CommandEnum $enum;

    public function __construct(string $name, CommandEnum $enum, bool $optional = false){
        parent::__construct($name, $optional);
        $this->enum = $enum;
    }

    public function getEnum() : CommandEnum{
        return $this->enum;
    }

    public function getNetworkType() : int{
        return 0;
    }

    public function getTypeName() : string{
        return $this->enum->getName();
    }

    public function accepts(string $text, CommandSender $sender) : bool{
        return $this->enum->match(CommandWords::unquote($text)) !== null;
    }

    public function read(string $text, CommandSender $sender) : mixed{
        return $this->enum->match(CommandWords::unquote($text));
    }
}