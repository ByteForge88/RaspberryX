<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandEnum;
use raspberry\command\CommandException;
use raspberry\command\CommandSender;
use raspberry\command\CommandWords;

/**
 * An item, with or without minecraft: in front. The command gets its full id, like
 * "minecraft:diamond".
 */
class ItemArgument extends EnumArgument{

    public function __construct(string $name, bool $optional = false){
        parent::__construct($name, CommandEnum::items(), $optional);
    }

    public function read(string $text, CommandSender $sender) : string{
        $name = CommandWords::unquote($text);
        return CommandEnum::toItemId($name) ?? throw new CommandException("commands.give.item.notFound", [$name]);
    }
}