<?php

declare(strict_types=1);

namespace raspberry\crafting;

class BrewingContainerRecipe{

    private string $id;

    private string $input;

    private RecipeIngredient $reagent;

    private string $output;

    public function __construct(string $id, string $input, RecipeIngredient $reagent, string $output){
        $this->id = $id;
        $this->input = $input;
        $this->reagent = $reagent;
        $this->output = $output;
    }

    public function getId() : string{
        return $this->id;
    }

    public function getInput() : string{
        return $this->input;
    }

    public function getReagent() : RecipeIngredient{
        return $this->reagent;
    }

    public function getOutput() : string{
        return $this->output;
    }
}