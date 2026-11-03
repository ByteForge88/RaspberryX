<?php

declare(strict_types=1);

namespace raspberry\crafting;

use raspberry\item\Item;

use function count;

class ShapelessRecipe extends CraftingRecipe{

    /** @var list<RecipeIngredient> */
    private array $input;

    /**
     * @param list<RecipeIngredient> $input
     */
    public function __construct(string $id, string $block, int $priority, array $input, array $output, ?RecipeUnlock $unlock, int $networkId){
        parent::__construct($id, $block, $priority, $output, $unlock, $networkId);
        $this->input = $input;
    }

    public function getIngredients() : array{
        return $this->input;
    }

    public function match(array $grid, int $size, int $times) : ?array{
        $slots = [];

        foreach($grid as $index => $item){
            if(!$item->isNull()){
                $slots[] = $index;
            }
        }

        if(count($slots) !== count($this->input)){
            return null;
        }

        $matched = [];

        return $this->assign($grid, $slots, 0, [], $times, $matched) ? $matched : null;
    }

    /**
     * Gives every filled grid slot its own ingredient, trying again when a choice leads nowhere.
     *
     * @param list<Item> $grid
     * @param list<int> $slots
     * @param array<int, true> $used ingredient indexes already taken
     * @param array<int, RecipeIngredient> $matched
     */
    private function assign(array $grid, array $slots, int $next, array $used, int $times, array &$matched) : bool{
        if($next === count($slots)){
            return true;
        }

        $item = $grid[$slots[$next]];

        foreach($this->input as $index => $ingredient){
            if(isset($used[$index]) || !$ingredient->matches($item) || $item->getCount() < $ingredient->getCount() * $times){
                continue;
            }

            $matched[$slots[$next]] = $ingredient;

            if($this->assign($grid, $slots, $next + 1, $used + [$index => true], $times, $matched)){
                return true;
            }

            unset($matched[$slots[$next]]);
        }

        return false;
    }
}