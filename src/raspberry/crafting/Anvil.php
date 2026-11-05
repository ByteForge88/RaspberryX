<?php

declare(strict_types=1);

namespace raspberry\crafting;

use raspberry\Server;

use raspberry\item\Item;
use raspberry\item\ItemIds;
use raspberry\item\ItemProperties;
use raspberry\item\enchantment\Enchantment;

use function intdiv;
use function max;
use function min;
use function str_starts_with;
use function substr;

class Anvil{

    /** at this cost the anvil says "Too Expensive!" outside creative */
    public const MAX_COST = 40;

    /** chance the anvil gets damaged each use */
    public const DAMAGE_CHANCE = 0.12;

    private function __construct(){
    }

    /**
     * @return array{Item, int, int}|null
     */
    public static function combine(Item $input, Item $material, ?string $newName, bool $creative) : ?array{
        if($input->isNull()){
            return null;
        }

        $result = clone $input;
        $cost = 0;
        $used = 0;
        $baseCost = self::getRepairCost($input) + ($material->isNull() ? 0 : self::getRepairCost($material));

        if(!$material->isNull()){
            if($input->getCount() > 1){
                return null;
            }

            $book = $material->getId() === ItemIds::ENCHANTED_BOOK && $material->hasEnchantments();

            if(self::isRepairMaterial($input, $material)){
                if($input->getDamage() === 0){
                    return null;
                }

                while($used < $material->getCount() && $result->getDamage() > 0){
                    $result->setDamage($result->getDamage() - intdiv($result->getMaxDurability(), 4));
                    ++$used;
                    ++$cost;
                }
            }elseif($book || ($material->getId() === $input->getId() && ($input->hasDurability() || $input->getId() === ItemIds::ENCHANTED_BOOK))){
                $repaired = false;

                if(!$book && $input->hasDurability()){
                    $left = ($input->getMaxDurability() - $input->getDamage()) + ($material->getMaxDurability() - $material->getDamage()) + intdiv($input->getMaxDurability() * 12, 100);
                    $damage = max(0, $input->getMaxDurability() - $left);

                    if($damage < $input->getDamage()){
                        $result->setDamage($damage);
                        $repaired = true;
                        $cost += 2;
                    }
                }

                [$merged, $any] = self::mergeEnchantments($input, $material, $result, $book);

                if(!$any && !$repaired){
                    return null;
                }

                $cost += $merged;
                $used = 1;
            }else{
                return null;
            }
        }

        $renamed = false;

        if($newName !== null && $newName !== ($input->getCustomName() ?? "")){
            $result->setCustomName($newName === "" ? null : $newName);
            $renamed = true;
            ++$cost;
        }

        if($cost === 0){
            return null;
        }

        $total = $cost + $baseCost;
        $onlyRenamed = $renamed && $cost === 1 && $material->isNull();

        if($onlyRenamed && $total >= self::MAX_COST){
            $total = self::MAX_COST - 1;
        }

        if($total >= self::MAX_COST && !$creative){
            return null;
        }

        if(!$onlyRenamed){
            self::setRepairCost($result, max(self::getRepairCost($input), $material->isNull() ? 0 : self::getRepairCost($material)) * 2 + 1);
        }

        return [$result, $total, $used];
    }

    public static function getRepairCost(Item $item) : int{
        return $item->getExtraTags()->getInt("RepairCost", 0) ?? 0;
    }

    public static function setRepairCost(Item $item, int $cost) : void{
        if($cost > 0){
            $item->getExtraTags()->setInt("RepairCost", $cost);
        }else{
            $item->getExtraTags()->removeTag("RepairCost");
        }
    }

    private static function isRepairMaterial(Item $input, Item $material) : bool{
        $repair = ItemProperties::getRepairMaterial($input->getId());

        if($repair === null){
            return false;
        }

        if(str_starts_with($repair, "#")){
            $items = Server::getInstance()->getCraftingManager()->getItemTags()[substr($repair, 1)] ?? [];

            foreach($items as $id){
                if($id === $material->getId()){
                    return true;
                }
            }

            return false;
        }

        return $material->getId() === $repair;
    }

    /**
     * Puts the material's enchantments on $result. Returns the levels it costs and whether any
     * enchantment could go on.
     *
     * @return array{int, bool}
     */
    private static function mergeEnchantments(Item $input, Item $material, Item $result, bool $book) : array{
        $cost = 0;
        $any = false;

        foreach($material->getEnchantments() as $id => $level){
            $enchantment = Enchantment::tryFrom($id);

            if($enchantment === null){
                continue;
            }

            $fits = $input->getId() === ItemIds::ENCHANTED_BOOK || $enchantment->canApplyTo($input, true);

            foreach($input->getEnchantments() as $otherId => $otherLevel){
                $other = Enchantment::tryFrom($otherId);

                if($other !== null && !$enchantment->isCompatibleWith($other)){
                    $fits = false;
                    ++$cost;
                }
            }

            if(!$fits){
                continue;
            }

            $current = $input->getEnchantmentLevel($enchantment);

            if($current > $level || ($current === $level && $level >= $enchantment->getMaxLevel())){
                continue;
            }

            $newLevel = $current === $level ? $level + 1 : $level;
            $perLevel = $book ? max(1, intdiv($enchantment->getAnvilCost(), 2)) : $enchantment->getAnvilCost();

            $result->addEnchantment($enchantment, min($newLevel, $enchantment->getMaxLevel()));
            $cost += $perLevel * ($newLevel - $current);
            $any = true;
        }

        return [$cost, $any];
    }
}