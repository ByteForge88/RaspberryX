<?php

declare(strict_types=1);

namespace raspberry\command\argument;

use raspberry\command\CommandEnum;
use raspberry\command\CommandException;
use raspberry\command\CommandSender;
use raspberry\command\CommandWords;

use raspberry\item\enchantment\Enchantment;

use function array_filter;
use function array_map;
use function array_values;

/**
 * An enchantment by name, like sharpness.
 */
class EnchantmentArgument extends EnumArgument{

    public function __construct(string $name, bool $optional = false){
        $names = array_map(fn(Enchantment $enchantment) : string => self::nameOf($enchantment), array_values(array_filter(Enchantment::cases(), fn(Enchantment $enchantment) : bool => $enchantment !== Enchantment::CUSTOM)));
        parent::__construct($name, new CommandEnum("Enchant", $names), $optional);
    }

    /**
     * The name /enchant knows it by.
     */
    public static function nameOf(Enchantment $enchantment) : string{
        return $enchantment === Enchantment::INFINITY ? "infinity" : $enchantment->getShortName();
    }

    public function read(string $text, CommandSender $sender) : Enchantment{
        $name = $this->enum->match(CommandWords::unquote($text)) ?? $text;
        $enchantment = $name === "infinity" ? Enchantment::INFINITY : Enchantment::fromName($name);
        return $enchantment ?? throw new CommandException("commands.enchant.notFound", [$name]);
    }
}