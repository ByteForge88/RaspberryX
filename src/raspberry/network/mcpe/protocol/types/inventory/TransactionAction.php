<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types\inventory;

use raspberry\network\mcpe\protocol\types\ItemStack;

class TransactionAction{

    public const SOURCE_CONTAINER = 0;
    public const SOURCE_WORLD = 2;
    public const SOURCE_CREATIVE = 3;

    public int $sourceType;

    public ?int $windowId;

    public ?int $sourceFlags;

    public int $slot;

    public ItemStack $oldItem;

    public ItemStack $newItem;

    public function __construct(int $sourceType, ?int $windowId, ?int $sourceFlags, int $slot, ItemStack $oldItem, ItemStack $newItem){
        $this->sourceType = $sourceType;
        $this->windowId = $windowId;
        $this->sourceFlags = $sourceFlags;
        $this->slot = $slot;
        $this->oldItem = $oldItem;
        $this->newItem = $newItem;
    }
}