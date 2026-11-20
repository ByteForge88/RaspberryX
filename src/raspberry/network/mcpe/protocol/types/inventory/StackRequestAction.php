<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol\types\inventory;

class StackRequestAction{

    public const int TAKE = 0;
    public const int PLACE = 1;
    public const int SWAP = 2;
    public const int DROP = 3;
    public const int DESTROY = 4;
    public const int CONSUME = 5;
    public const int CREATE = 6;
    public const int LAB_TABLE_COMBINE = 9;
    public const int BEACON_PAYMENT = 10;
    public const int MINE_BLOCK = 11;
    public const int CRAFT_RECIPE = 12;
    public const int CRAFT_RECIPE_AUTO = 13;
    public const int CRAFT_CREATIVE = 14;
    public const int CRAFT_RECIPE_OPTIONAL = 15;
    public const int CRAFT_GRINDSTONE = 16;
    public const int CRAFT_LOOM = 17;
    public const int CRAFT_RESERVED = 18;
    public const int CRAFT_NOT_IMPLEMENTED = 19;
    public const int CRAFT_RESULTS_DEPRECATED = 20;

    public int $type;

    public int $count = 0;

    public ?StackRequestSlot $source = null;

    public ?StackRequestSlot $destination = null;

    public int $creativeItemId = 0;

    public int $recipeNetworkId = 0;

    public int $timesCrafted = 0;

    public int $resultsSlot = 0;

    /** anvil: which filter string is the new name, -1 for none */
    public int $filterStringIndex = -1;

    /** loom: the banner pattern ID */
    public string $pattern = "";

    /** beacon: the effects picked, 0 for none */
    public int $primaryEffect = 0;
    public int $secondaryEffect = 0;

    public function __construct(int $type){
        $this->type = $type;
    }
}