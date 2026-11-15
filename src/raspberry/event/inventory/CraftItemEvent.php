<?php

declare(strict_types=1);

namespace raspberry\event\inventory;

use raspberry\crafting\CraftingRecipe;

use raspberry\event\Cancellable;
use raspberry\event\CancellableTrait;
use raspberry\event\player\PlayerEvent;

use raspberry\item\Item;

use raspberry\player\Player;

/**
 * A player is about to craft, at a crafting table, the inventory grid, a stonecutter or another
 * work block. Cancelling it keeps the ingredients.
 */
class CraftItemEvent extends PlayerEvent implements Cancellable{
    use CancellableTrait;

    private CraftingRecipe $recipe;

    private int $times;

    public function __construct(Player $player, CraftingRecipe $recipe, int $times){
        $this->player = $player;
        $this->recipe = $recipe;
        $this->times = $times;
    }

    public function getRecipe() : CraftingRecipe{
        return $this->recipe;
    }

    /**
     * How many times at once, more than one when shift-clicking.
     */
    public function getTimes() : int{
        return $this->times;
    }

    /**
     * @return list<Item>
     */
    public function getOutputs() : array{
        return $this->recipe->getOutput();
    }
}