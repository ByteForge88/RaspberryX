<?php

declare(strict_types=1);

namespace raspberry\crafting;

use raspberry\item\Item;
use raspberry\item\ItemIds;
use raspberry\item\enchantment\Enchantment;

use function intdiv;
use function max;
use function mt_rand;

class Grindstone{

    private function __construct(){
    }

    /**
     * @return array{Item, int}|null
     */
    public static function grind(Item $first, Item $second) : ?array{
        if(($first->isNull() && $second->isNull()) || $first->getCount() > 1 || $second->getCount() > 1){
            return null;
        }

        if(!$first->isNull() && !$second->isNull()){
            if($first->getId() !== $second->getId() || (!$first->hasDurability() && $first->getId() !== ItemIds::ENCHANTED_BOOK)){
                return null;
            }

            $result = clone $first;

            foreach($second->getEnchantments() as $id => $level){
                $enchantment = Enchantment::tryFrom($id);

                if($enchantment !== null && $level > $result->getEnchantmentLevel($enchantment)){
                    $result->addEnchantment($enchantment, $level);
                }
            }

            if($first->hasDurability()){
                $left = ($first->getMaxDurability() - $first->getDamage()) + ($second->getMaxDurability() - $second->getDamage()) + intdiv($first->getMaxDurability() * 5, 100);
                $result->setDamage(max(0, $first->getMaxDurability() - $left));
            }
        }else{
            $result = clone ($first->isNull() ? $second : $first);

            if(self::getPower($result) === 0){
                return null;
            }
        }

        $power = self::getPower($result);
        $experience = 0;

        if($power > 0){
            $half = intdiv($power + 1, 2);
            $experience = $half + mt_rand(0, $half - 1);
        }

        foreach($result->getEnchantments() as $id => $level){
            $enchantment = Enchantment::from($id);

            if(!$enchantment->isCurse()){
                $result->removeEnchantment($enchantment);
            }
        }

        Anvil::setRepairCost($result, 0);

        if($result->getId() === ItemIds::ENCHANTED_BOOK && !$result->hasEnchantments()){
            $result = new Item(ItemIds::BOOK);
        }

        return [$result, $experience];
    }

    private static function getPower(Item $item) : int{
        $power = 0;

        foreach($item->getEnchantments() as $id => $level){
            $enchantment = Enchantment::tryFrom($id);

            if($enchantment !== null && !$enchantment->isCurse()){
                $power += $enchantment->getPowerRange($level)[0];
            }
        }

        return $power;
    }
}