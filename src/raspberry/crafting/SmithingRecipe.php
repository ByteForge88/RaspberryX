<?php

declare(strict_types=1);

namespace raspberry\crafting;

use raspberry\item\Item;

class SmithingRecipe{

    private string $id;

    private RecipeIngredient $template;

    private RecipeIngredient $base;

    private RecipeIngredient $addition;

    private ?Item $output;

    private int $networkId;

    public function __construct(string $id, RecipeIngredient $template, RecipeIngredient $base, RecipeIngredient $addition, ?Item $output, int $networkId){
        $this->id = $id;
        $this->template = $template;
        $this->base = $base;
        $this->addition = $addition;
        $this->output = $output;
        $this->networkId = $networkId;
    }

    public function getId() : string{
        return $this->id;
    }

    public function getTemplate() : RecipeIngredient{
        return $this->template;
    }

    public function getBase() : RecipeIngredient{
        return $this->base;
    }

    public function getAddition() : RecipeIngredient{
        return $this->addition;
    }

    public function isTrim() : bool{
        return $this->output === null;
    }

    public function getOutput() : ?Item{
        return $this->output === null ? null : clone $this->output;
    }

    public function getNetworkId() : int{
        return $this->networkId;
    }
}