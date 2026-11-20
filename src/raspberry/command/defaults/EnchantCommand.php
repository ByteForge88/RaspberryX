<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\Command;
use raspberry\command\CommandEnum;
use raspberry\command\CommandException;
use raspberry\command\CommandOutput;
use raspberry\command\CommandParameter;
use raspberry\command\CommandSender;

use raspberry\item\enchantment\Enchantment;
use raspberry\item\enchantment\EnchantmentException;

use raspberry\player\Player;

use function array_filter;
use function array_map;
use function array_values;
use function is_int;

class EnchantCommand extends Command{

    public function __construct(){
        parent::__construct("enchant", "commands.enchant.description", "raspberry.command.enchant");

        $names = array_map(fn(Enchantment $enchantment) : string => self::nameOf($enchantment), array_values(array_filter(Enchantment::cases(), fn(Enchantment $enchantment) : bool => $enchantment !== Enchantment::CUSTOM)));

        $this->addOverload(CommandParameter::target("player"), CommandParameter::int("enchantmentId"), CommandParameter::int("level", true));
        $this->addOverload(CommandParameter::target("player"), CommandParameter::enum("enchantmentName", new CommandEnum("Enchant", $names)), CommandParameter::int("level", true));
    }

    private static function nameOf(Enchantment $enchantment) : string{
        return $enchantment === Enchantment::INFINITY ? "infinity" : $enchantment->getShortName();
    }

    /**
     * @throws EnchantmentException
     */
    public function onSent(CommandSender $sender, CommandOutput $output, array $args) : void{
        /** @var list<Player> $players */
        $players = $args["player"];
        $given = $args["enchantmentId"] ?? $args["enchantmentName"];
        $enchantment = is_int($given) ? Enchantment::tryFrom($given) : ($given === "infinity" ? Enchantment::INFINITY : Enchantment::fromName($given));

        if($enchantment === null || $enchantment === Enchantment::CUSTOM){
            throw new CommandException("commands.enchant.notFound", [$given]);
        }

        $level = $args["level"] ?? 1;

        if($level < 1 || $level > $enchantment->getMaxLevel()){
            throw new CommandException("commands.enchant.invalidLevel", [$level]);
        }

        foreach($players as $player){
            $item = $player->getInventory()->getItemInHand();

            if($item->isNull()){
                $output->error("commands.enchant.noItem");
                continue;
            }

            if(!$enchantment->canApplyTo($item, true)){
                $output->error("commands.enchant.cantEnchant", [self::nameOf($enchantment)]);
                continue;
            }

            foreach($item->getEnchantments() as $other => $otherLevel){
                $other = Enchantment::tryFrom($other);

                if($other !== null && $other !== $enchantment && !$enchantment->isCompatibleWith($other)){
                    $output->error("commands.enchant.cantCombine", [self::nameOf($enchantment), self::nameOf($other)]);
                    continue 2;
                }
            }

            $player->getInventory()->setItemInHand((clone $item)->addEnchantment($enchantment, $level));
            $output->success("commands.enchant.success", [$player->getName()]);
        }
    }
}