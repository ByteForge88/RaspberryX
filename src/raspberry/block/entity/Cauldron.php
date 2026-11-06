<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\nbt\tag\CompoundTag;
use raspberry\nbt\tag\ListTag;
use raspberry\nbt\tag\Tag;

class Cauldron extends BlockEntity{

    public const TYPE_NONE = -1;
    public const TYPE_NORMAL = 0;
    public const TYPE_SPLASH = 1;
    public const TYPE_LINGERING = 2;

    /** the potion meta, -1 for water */
    private int $potionId = -1;

    private int $potionType = self::TYPE_NONE;

    /** dyed water, 0xRRGGBB */
    private ?int $color = null;

    public function getSaveId() : string{
        return "Cauldron";
    }

    public function hasPotion() : bool{
        return $this->potionId >= 0 && $this->potionType !== self::TYPE_NONE;
    }

    public function getPotionId() : int{
        return $this->potionId;
    }

    public function getPotionType() : int{
        return $this->potionType;
    }

    public function setPotion(int $potionId, int $potionType) : void{
        $this->potionId = $potionId;
        $this->potionType = $potionType;
        $this->color = null;
        $this->setChanged();
    }

    public function getColor() : ?int{
        return $this->color;
    }

    public function setColor(?int $color) : void{
        $this->color = $color;
        $this->setChanged();
    }

    /**
     * Back to plain water, or nothing once the cauldron is empty.
     */
    public function clear() : void{
        $this->potionId = -1;
        $this->potionType = self::TYPE_NONE;
        $this->color = null;
        $this->setChanged();
    }

    public function readData(CompoundTag $nbt) : void{
        $this->potionId = $nbt->getShort("PotionId", -1) ?? -1;
        $this->potionType = $nbt->getShort("PotionType", self::TYPE_NONE) ?? self::TYPE_NONE;
        $color = $nbt->getInt("CustomColor");
        $this->color = $color === null ? null : $color & 0xffffff;

        if($this->potionType < self::TYPE_NONE || $this->potionType > self::TYPE_LINGERING){
            $this->potionId = -1;
            $this->potionType = self::TYPE_NONE;
        }
    }

    public function writeData(CompoundTag $nbt) : void{
        $nbt->setShort("PotionId", $this->potionId);
        $nbt->setShort("PotionType", $this->potionType);
        $nbt->setTag("Items", new ListTag([], Tag::TAG_COMPOUND));

        if($this->color !== null){
            $nbt->setInt("CustomColor", -16777216 | $this->color);
        }
    }
}