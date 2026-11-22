<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandEnum;

/**
 * The name of a command. Each player gets the commands they can use in their suggestions.
 */
class CommandNameArgument extends EnumArgument{

    public function __construct(string $name, bool $optional = false){
        parent::__construct($name, CommandEnum::commandNames(), $optional);
    }
}