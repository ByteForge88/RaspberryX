<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandEnum;
use raspberry\command\CommandException;
use raspberry\command\CommandSender;
use raspberry\command\CommandWords;

use raspberry\entity\effect\Effect;

/**
 * A status effect, like speed or minecraft:speed.
 */
class EffectArgument extends EnumArgument{

    public function __construct(string $name, bool $optional = false){
        parent::__construct($name, CommandEnum::effects(), $optional);
    }

    public function read(string $text, CommandSender $sender) : Effect{
        $name = $this->enum->match(CommandWords::unquote($text)) ?? $text;
        return Effect::fromName($name) ?? throw new CommandException("commands.effect.notFound", [$name]);
    }
}