<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types;

use raspberry\nbt\tag\CompoundTag;

class ItemStack{

    /** item runtime ID from the item registry, 0 for an empty slot */
    public int $networkId;

    public int $count;

    /** the data value (bed color, potion type...) */
    public int $meta;

    /** network ID of the block a block item places, 0 for other items */
    public int $blockRuntimeId;

    /** the item's own NBT (durability, name, lore, enchantments), null when it has none */
    public ?CompoundTag $nbt;

    /** @var list<string> blocks the item can be placed on in adventure mode */
    public array $canPlaceOn = [];

    /** @var list<string> blocks the item can break in adventure mode */
    public array $canDestroy = [];

    /** shields only: the tick the shield started blocking */
    public int $blockingTick = 0;

    public function __construct(int $networkId, int $count, int $meta = 0, int $blockRuntimeId = 0, ?CompoundTag $nbt = null){
        $this->networkId = $networkId;
        $this->count = $count;
        $this->meta = $meta;
        $this->blockRuntimeId = $blockRuntimeId;
        $this->nbt = $nbt;
    }

    public static function empty() : self{
        return new self(0, 0);
    }

    public function isEmpty() : bool{
        return $this->networkId === 0 || $this->count === 0;
    }
}