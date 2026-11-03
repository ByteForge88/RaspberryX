<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types\inventory;

class StackRequestAction{

    public const TAKE = 0;
    public const PLACE = 1;
    public const SWAP = 2;
    public const DROP = 3;
    public const DESTROY = 4;
    public const CONSUME = 5;
    public const CREATE = 6;
    public const LAB_TABLE_COMBINE = 9;
    public const BEACON_PAYMENT = 10;
    public const MINE_BLOCK = 11;
    public const CRAFT_RECIPE = 12;
    public const CRAFT_RECIPE_AUTO = 13;
    public const CRAFT_CREATIVE = 14;
    public const CRAFT_RECIPE_OPTIONAL = 15;
    public const CRAFT_GRINDSTONE = 16;
    public const CRAFT_LOOM = 17;
    public const CRAFT_RESERVED = 18;
    public const CRAFT_NOT_IMPLEMENTED = 19;
    public const CRAFT_RESULTS_DEPRECATED = 20;

    public int $type;

    public int $count = 0;

    public ?StackRequestSlot $source = null;

    public ?StackRequestSlot $destination = null;

    public int $creativeItemId = 0;

    public int $recipeNetworkId = 0;

    public int $timesCrafted = 0;

    public int $resultsSlot = 0;

    public function __construct(int $type){
        $this->type = $type;
    }
}