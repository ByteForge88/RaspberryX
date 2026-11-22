<?php

declare(strict_types=1);

namespace raspberry\command\defaults;

use raspberry\command\CommandBuilder;
use raspberry\command\CommandException;
use raspberry\command\CommandSender;
use raspberry\command\ParameterBuilder;

use raspberry\command\argument\EnchantmentArgument;
use raspberry\command\argument\IntArgument;
use raspberry\command\argument\TargetArgument;

use raspberry\item\enchantment\Enchantment;
use raspberry\item\enchantment\EnchantmentException;

use raspberry\lang\Translation;

use raspberry\player\Player;

class EnchantCommand extends CommandBuilder{

    protected function configure() : void{
        $this->setName("enchant");
        $this->setDescription("commands.enchant.description");
        $this->setPermission("raspberry.command.enchant");

        $builder = new ParameterBuilder(0);
        $builder->addArgument(0, new TargetArgument("player"));
        $builder->addArgument(1, new IntArgument("enchantmentId"));
        $builder->addArgument(1, new EnchantmentArgument("enchantmentName"));
        $builder->addArgument(2, new IntArgument("level", true));
        $this->addParameterBuilder($builder);
    }

    /**
     * @throws EnchantmentException
     */
    public function onSent(CommandSender $sender, string $label, array $args) : void{
        /** @var list<Player> $players */
        $players = $args["player"];

        if(isset($args["enchantmentId"])){
            $enchantment = Enchantment::tryFrom($args["enchantmentId"]);

            if($enchantment === null || $enchantment === Enchantment::CUSTOM){
                throw new CommandException("commands.enchant.notFound", [$args["enchantmentId"]]);
            }
        }else{
            $enchantment = $args["enchantmentName"];
        }

        $level = $args["level"] ?? 1;

        if($level < 1 || $level > $enchantment->getMaxLevel()){
            throw new CommandException("commands.enchant.invalidLevel", [$level]);
        }

        $name = EnchantmentArgument::nameOf($enchantment);

        foreach($players as $player){
            $item = $player->getInventory()->getItemInHand();

            if($item->isNull()){
                $this->failure(new Translation("commands.enchant.noItem"));
                continue;
            }

            if(!$enchantment->canApplyTo($item, true)){
                $this->failure(new Translation("commands.enchant.cantEnchant", [$name]));
                continue;
            }

            foreach($item->getEnchantments() as $other => $otherLevel){
                $other = Enchantment::tryFrom($other);

                if($other !== null && $other !== $enchantment && !$enchantment->isCompatibleWith($other)){
                    $this->failure(new Translation("commands.enchant.cantCombine", [$name, EnchantmentArgument::nameOf($other)]));
                    continue 2;
                }
            }

            $player->getInventory()->setItemInHand((clone $item)->addEnchantment($enchantment, $level));
            $this->success(new Translation("commands.enchant.success", [$player->getName()]));
        }
    }
}