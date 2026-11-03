<?php

declare(strict_types=1);

namespace raspberry\crafting;

class RecipeUnlock{

    public const CONTEXT_NONE = 0;
    public const CONTEXT_ALWAYS_UNLOCKED = 1;
    public const CONTEXT_PLAYER_IN_WATER = 2;
    public const CONTEXT_PLAYER_HAS_MANY_ITEMS = 3;

    public const CONTEXTS = [
        "None" => self::CONTEXT_NONE,
        "AlwaysUnlocked" => self::CONTEXT_ALWAYS_UNLOCKED,
        "PlayerInWater" => self::CONTEXT_PLAYER_IN_WATER,
        "PlayerHasManyItems" => self::CONTEXT_PLAYER_HAS_MANY_ITEMS
    ];

    private int $context;

    /** @var list<RecipeIngredient> */
    private array $items;

    /**
     * @param list<RecipeIngredient> $items
     */
    public function __construct(int $context, array $items){
        $this->context = $context;
        $this->items = $items;
    }

    public function getContext() : int{
        return $this->context;
    }

    /**
     * @return list<RecipeIngredient>
     */
    public function getItems() : array{
        return $this->items;
    }
}