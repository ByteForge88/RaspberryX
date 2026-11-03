<?php

declare(strict_types=1);

namespace raspberry\crafting;

use raspberry\item\Item;

class ShapedRecipe extends CraftingRecipe{

    private int $width;

    private int $height;

    /** @var list<RecipeIngredient|null> */
    private array $input;

    /**
     * @param list<RecipeIngredient|null> $input
     */
    public function __construct(string $id, string $block, int $priority, int $width, int $height, array $input, array $output, ?RecipeUnlock $unlock, int $networkId){
        parent::__construct($id, $block, $priority, $output, $unlock, $networkId);
        $this->width = $width;
        $this->height = $height;
        $this->input = $input;
    }

    public function getWidth() : int{
        return $this->width;
    }

    public function getHeight() : int{
        return $this->height;
    }

    /**
     * @return list<RecipeIngredient|null>
     */
    public function getInput() : array{
        return $this->input;
    }

    public function getIngredients() : array{
        $ingredients = [];

        foreach($this->input as $ingredient){
            if($ingredient !== null){
                $ingredients[] = $ingredient;
            }
        }

        return $ingredients;
    }

    public function match(array $grid, int $size, int $times) : ?array{
        for($offsetY = 0; $offsetY + $this->height <= $size; ++$offsetY){
            for($offsetX = 0; $offsetX + $this->width <= $size; ++$offsetX){
                foreach([false, true] as $mirrored){
                    $matched = $this->matchAt($grid, $size, $times, $offsetX, $offsetY, $mirrored);

                    if($matched !== null){
                        return $matched;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param list<Item> $grid
     *
     * @return array<int, RecipeIngredient>|null
     */
    private function matchAt(array $grid, int $size, int $times, int $offsetX, int $offsetY, bool $mirrored) : ?array{
        $matched = [];

        for($y = 0; $y < $size; ++$y){
            for($x = 0; $x < $size; ++$x){
                $item = $grid[$y * $size + $x];
                $column = $x - $offsetX;
                $row = $y - $offsetY;
                $ingredient = null;

                if($column >= 0 && $column < $this->width && $row >= 0 && $row < $this->height){
                    $ingredient = $this->input[$row * $this->width + ($mirrored ? $this->width - 1 - $column : $column)];
                }

                if($ingredient === null){
                    if(!$item->isNull()){
                        return null;
                    }

                    continue;
                }

                if(!$ingredient->matches($item) || $item->getCount() < $ingredient->getCount() * $times){
                    return null;
                }

                $matched[$y * $size + $x] = $ingredient;
            }
        }

        return $matched;
    }
}