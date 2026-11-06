<?php

declare(strict_types=1);

namespace raspberry\inventory;

use raspberry\item\Item;

use function preg_match;

class ArmorInventory extends Inventory{

    public const SLOT_HEAD = 0;
    public const SLOT_CHEST = 1;
    public const SLOT_LEGS = 2;
    public const SLOT_FEET = 3;

    public function __construct(){
        parent::__construct(4);
    }

    /**
     * The slot an item is worn in, null when it is not worn.
     */
    public static function getSlotFor(string $id) : ?int{
        return match(true){
            preg_match('/^minecraft:(\w+_helmet|carved_pumpkin|\w+_head|\w+_skull)$/D', $id) === 1 => self::SLOT_HEAD,
            preg_match('/^minecraft:(\w+_chestplate|elytra)$/D', $id) === 1 => self::SLOT_CHEST,
            preg_match('/^minecraft:\w+_leggings$/D', $id) === 1 => self::SLOT_LEGS,
            preg_match('/^minecraft:\w+_boots$/D', $id) === 1 => self::SLOT_FEET,
            default => null
        };
    }

    public function getHelmet() : Item{
        return $this->getItem(self::SLOT_HEAD);
    }

    public function getChestplate() : Item{
        return $this->getItem(self::SLOT_CHEST);
    }

    public function getLeggings() : Item{
        return $this->getItem(self::SLOT_LEGS);
    }

    public function getBoots() : Item{
        return $this->getItem(self::SLOT_FEET);
    }

    public function setHelmet(Item $item) : void{
        $this->setItem(self::SLOT_HEAD, $item);
    }

    public function setChestplate(Item $item) : void{
        $this->setItem(self::SLOT_CHEST, $item);
    }

    public function setLeggings(Item $item) : void{
        $this->setItem(self::SLOT_LEGS, $item);
    }

    public function setBoots(Item $item) : void{
        $this->setItem(self::SLOT_FEET, $item);
    }
}