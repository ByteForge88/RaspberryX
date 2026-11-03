<?php

declare(strict_types=1);

namespace raspberry\inventory;

use raspberry\item\Item;

class ArmorInventory extends Inventory{

    public const SLOT_HEAD = 0;
    public const SLOT_CHEST = 1;
    public const SLOT_LEGS = 2;
    public const SLOT_FEET = 3;

    public function __construct(){
        parent::__construct(4);
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