<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types\recipe;

use raspberry\network\mcpe\protocol\types\ItemStack;

use raspberry\utils\Uuid;

class CraftingRecipeData{

    public string $id;

    public int $width;

    public int $height;

    /** @var list<ItemDescriptor> */
    public array $input;

    /** @var list<ItemStack> */
    public array $output;

    public Uuid $uuid;

    public string $block;

    public int $priority;

    public ?int $unlockContext;

    /** @var list<ItemDescriptor> */
    public array $unlockItems;

    public int $networkId;

    /**
     * @param list<ItemDescriptor> $input
     * @param list<ItemStack>      $output
     * @param list<ItemDescriptor> $unlockItems
     */
    public function __construct(string $id, int $width, int $height, array $input, array $output, Uuid $uuid, string $block, int $priority, ?int $unlockContext, array $unlockItems, int $networkId){
        $this->id = $id;
        $this->width = $width;
        $this->height = $height;
        $this->input = $input;
        $this->output = $output;
        $this->uuid = $uuid;
        $this->block = $block;
        $this->priority = $priority;
        $this->unlockContext = $unlockContext;
        $this->unlockItems = $unlockItems;
        $this->networkId = $networkId;
    }

    public function isShaped() : bool{
        return $this->width > 0;
    }
}