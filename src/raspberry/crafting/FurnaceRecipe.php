<?php

declare(strict_types=1);

namespace raspberry\crafting;

use raspberry\item\Item;

class FurnaceRecipe{

    private string $id;

    /** @var list<string> */
    private array $blocks;

    private int $priority;

    private RecipeIngredient $input;

    private Item $output;

    /**
     * @param list<string> $blocks
     */
    public function __construct(string $id, array $blocks, int $priority, RecipeIngredient $input, Item $output){
        $this->id = $id;
        $this->blocks = $blocks;
        $this->priority = $priority;
        $this->input = $input;
        $this->output = $output;
    }

    public function getId() : string{
        return $this->id;
    }

    /**
     * @return list<string>
     */
    public function getBlocks() : array{
        return $this->blocks;
    }

    public function getPriority() : int{
        return $this->priority;
    }

    public function getInput() : RecipeIngredient{
        return $this->input;
    }

    public function getOutput() : Item{
        return clone $this->output;
    }
}