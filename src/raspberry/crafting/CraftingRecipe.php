<?php

declare(strict_types=1);

namespace raspberry\crafting;

use raspberry\item\Item;

use function array_map;

abstract class CraftingRecipe{

    public const BLOCK_CRAFTING_TABLE = "crafting_table";

    private string $id;

    private string $block;

    private int $priority;

    /** @var list<Item> */
    private array $output;

    private ?RecipeUnlock $unlock;

    private int $networkId;

    /**
     * @param list<Item> $output the first item is the result, the others are given back (empty buckets)
     */
    public function __construct(string $id, string $block, int $priority, array $output, ?RecipeUnlock $unlock, int $networkId){
        $this->id = $id;
        $this->block = $block;
        $this->priority = $priority;
        $this->output = $output;
        $this->unlock = $unlock;
        $this->networkId = $networkId;
    }

    public function getId() : string{
        return $this->id;
    }

    public function getBlock() : string{
        return $this->block;
    }

    public function getPriority() : int{
        return $this->priority;
    }

    /**
     * @return list<Item>
     */
    public function getOutput() : array{
        return array_map(fn(Item $item) => clone $item, $this->output);
    }

    public function getUnlock() : ?RecipeUnlock{
        return $this->unlock;
    }

    /**
     * The number the client names the recipe by, never 0.
     */
    public function getNetworkId() : int{
        return $this->networkId;
    }

    /**
     * @return list<RecipeIngredient>
     */
    abstract public function getIngredients() : array;

    /**
     * Which ingredient each item of a square crafting grid is used as, when the grid holds this
     * recipe $times over. Null when it does not.
     *
     * @param list<Item> $grid $size * $size items, row by row
     *
     * @return array<int, RecipeIngredient>|null grid index => ingredient, for every item in the grid
     */
    abstract public function match(array $grid, int $size, int $times) : ?array;

}