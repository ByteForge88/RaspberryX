<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandEnum;
use raspberry\command\CommandSender;

/**
 * true or false.
 */
class BoolArgument extends EnumArgument{

    public function __construct(string $name, bool $optional = false){
        parent::__construct($name, CommandEnum::boolean(), $optional);
    }

    public function read(string $text, CommandSender $sender) : bool{
        return parent::read($text, $sender) === "true";
    }
}