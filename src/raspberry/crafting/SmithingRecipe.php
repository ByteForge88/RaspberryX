<?php

declare(strict_types=1);

namespace raspberry\crafting;

use raspberry\item\Item;

use raspberry\nbt\tag\CompoundTag;

use function str_replace;
use function substr;

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

    /**
     * Whether the three items in the smithing table fit this recipe.
     */
    public function matches(Item $template, Item $base, Item $addition) : bool{
        return $this->template->matches($template) && $this->base->matches($base) && $this->addition->matches($addition);
    }

    /**
     * The upgraded or trimmed item, one of it.
     */
    public function apply(Item $template, Item $base, Item $addition) : Item{
        if($this->output === null){
            $result = (clone $base)->setCount(1);
            $result->getExtraTags()->setTag("Trim", (new CompoundTag())
                ->setString("Material", self::getTrimMaterial($addition->getId()))
                ->setString("Pattern", str_replace("_armor_trim_smithing_template", "", substr($template->getId(), 10))));

            return $result;
        }

        return $base->withId($this->output->getId())->setCount(1);
    }

    private static function getTrimMaterial(string $id) : string{
        return match($id){
            "minecraft:amethyst_shard" => "amethyst",
            "minecraft:copper_ingot" => "copper",
            "minecraft:gold_ingot" => "gold",
            "minecraft:iron_ingot" => "iron",
            "minecraft:lapis_lazuli" => "lapis",
            "minecraft:netherite_ingot" => "netherite",
            "minecraft:resin_brick" => "resin",
            default => substr($id, 10)
        };
    }
}