<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types\recipe;

use raspberry\network\mcpe\protocol\types\ItemStack;

class SmithingRecipeData{

    public string $id;

    public ItemDescriptor $template;

    public ItemDescriptor $base;

    public ItemDescriptor $addition;

    public ?ItemStack $output;

    public string $block;

    public int $networkId;

    public function __construct(string $id, ItemDescriptor $template, ItemDescriptor $base, ItemDescriptor $addition, ?ItemStack $output, string $block, int $networkId){
        $this->id = $id;
        $this->template = $template;
        $this->base = $base;
        $this->addition = $addition;
        $this->output = $output;
        $this->block = $block;
        $this->networkId = $networkId;
    }
}