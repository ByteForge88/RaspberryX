<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandEnum;
use raspberry\command\CommandSender;

use raspberry\entity\EntityFactory;

use function str_starts_with;
use function substr;

/**
 * A kind of entity that can be summoned, like zombie or minecraft:zombie. The command gets its
 * full id.
 */
class EntityTypeArgument extends EnumArgument{

    public function __construct(string $name, bool $optional = false){
        $names = [];

        foreach(EntityFactory::getRegistered() as $id){
            $names[] = substr($id, 10);
            $names[] = $id;
        }

        parent::__construct($name, new CommandEnum("EntityType", $names), $optional);
    }

    public function read(string $text, CommandSender $sender) : string{
        $name = parent::read($text, $sender);
        return str_starts_with($name, "minecraft:") ? $name : "minecraft:" . $name;
    }
}