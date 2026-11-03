<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types;

class ItemInstance{

    public int $stackNetworkId;

    public ItemStack $stack;

    public function __construct(int $stackNetworkId, ItemStack $stack){
        $this->stackNetworkId = $stackNetworkId;
        $this->stack = $stack;
    }

    public static function empty() : self{
        return new self(0, ItemStack::empty());
    }
}