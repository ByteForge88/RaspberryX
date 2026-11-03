<?php

declare(strict_types=1);

namespace raspberry\crafting;

class BrewingRecipe{

    private string $id;

    private int $inputPotion;

    private RecipeIngredient $reagent;

    private int $outputPotion;

    public function __construct(string $id, int $inputPotion, RecipeIngredient $reagent, int $outputPotion){
        $this->id = $id;
        $this->inputPotion = $inputPotion;
        $this->reagent = $reagent;
        $this->outputPotion = $outputPotion;
    }

    public function getId() : string{
        return $this->id;
    }

    public function getInputPotion() : int{
        return $this->inputPotion;
    }

    public function getReagent() : RecipeIngredient{
        return $this->reagent;
    }

    public function getOutputPotion() : int{
        return $this->outputPotion;
    }
}