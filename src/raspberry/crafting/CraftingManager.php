<?php

declare(strict_types=1);

namespace raspberry\crafting;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use RuntimeException;
use UnexpectedValueException;

use function array_search;
use function in_array;
use function dirname;
use function file_get_contents;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;

use const JSON_THROW_ON_ERROR;

class CraftingManager{

    /** @var array<string, list<string>> item tag => item IDs */
    private array $itemTags = [];

    /** @var list<string> potion type names, indexed by data value */
    private array $potionTypes = [];

    /** @var array<int, CraftingRecipe> network ID => recipe */
    private array $craftingRecipes = [];

    /** @var array<string, CraftingRecipe> recipe ID => recipe */
    private array $recipesById = [];

    /** @var list<FurnaceRecipe> */
    private array $furnaceRecipes = [];

    /** @var list<BrewingRecipe> */
    private array $brewingRecipes = [];

    /** @var list<BrewingContainerRecipe> */
    private array $brewingContainerRecipes = [];

    /** @var array<int, SmithingRecipe> network ID => recipe */
    private array $smithingRecipes = [];

    /** @var array<string, list<CraftingRecipe>> item ID => recipes getting that item unlocks */
    private array $unlockedByItem = [];

    /** @var array<int, list<CraftingRecipe>> unlock context => recipes */
    private array $unlockedByContext = [];

    private int $nextNetworkId = 1;

    public function __construct(?string $path = null){
        $path ??= dirname(__DIR__, 3) . "/resources/vanilla/recipes.json";
        $contents = @file_get_contents($path);

        if($contents === false){
            throw new RuntimeException("Cannot read recipes: {$path}");
        }

        $data = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);

        if(!is_array($data) || !is_array($data["item_tags"] ?? null) || !is_array($data["potion_types"] ?? null)){
            throw new UnexpectedValueException("Recipes have an invalid layout: {$path}");
        }

        $this->itemTags = $data["item_tags"];
        $this->potionTypes = $data["potion_types"];

        foreach($data["shaped"] ?? [] as $entry){
            $input = [];

            foreach($entry["input"] as $cell){
                $input[] = $cell === null ? null : $this->ingredient($cell);
            }

            $this->addCraftingRecipe(new ShapedRecipe($entry["id"], $entry["block"], $entry["priority"], $entry["width"], $entry["height"], $input, $this->items($entry["output"]), $this->unlock($entry["unlock"]), $this->nextNetworkId++));
        }

        foreach($data["shapeless"] ?? [] as $entry){
            $input = [];

            foreach($entry["input"] as $cell){
                $input[] = $this->ingredient($cell);
            }

            $this->addCraftingRecipe(new ShapelessRecipe($entry["id"], $entry["block"], $entry["priority"], $input, $this->items($entry["output"]), $this->unlock($entry["unlock"]), $this->nextNetworkId++));
        }

        foreach($data["furnace"] ?? [] as $entry){
            $this->furnaceRecipes[] = new FurnaceRecipe($entry["id"], $entry["blocks"], $entry["priority"], $this->ingredient($entry["input"]), $this->item($entry["output"]));
        }

        foreach($data["brewing_mix"] ?? [] as $entry){
            $this->brewingRecipes[] = new BrewingRecipe($entry["id"], $entry["input"], $this->ingredient($entry["reagent"]), $entry["output"]);
        }

        foreach($data["brewing_container"] ?? [] as $entry){
            $this->brewingContainerRecipes[] = new BrewingContainerRecipe($entry["id"], $entry["input"], $this->ingredient($entry["reagent"]), $entry["output"]);
        }

        foreach(["smithing_transform", "smithing_trim"] as $kind){
            foreach($data[$kind] ?? [] as $entry){
                $recipe = new SmithingRecipe($entry["id"], $this->ingredient($entry["template"]), $this->ingredient($entry["base"]), $this->ingredient($entry["addition"]), isset($entry["output"]) ? $this->item($entry["output"]) : null, $this->nextNetworkId++);
                $this->smithingRecipes[$recipe->getNetworkId()] = $recipe;
            }
        }
    }

    /**
     * @return array<string, list<string>>
     */
    public function getItemTags() : array{
        return $this->itemTags;
    }

    /**
     * The name of a potion data value ("awkward", "long_swiftness"...), null when unknown.
     */
    public function getPotionType(int $meta) : ?string{
        return $this->potionTypes[$meta] ?? null;
    }

    public function getPotionMeta(string $type) : ?int{
        $meta = array_search($type, $this->potionTypes, true);

        return is_int($meta) ? $meta : null;
    }

    /**
     * @return array<int, CraftingRecipe> network ID => recipe
     */
    public function getCraftingRecipes() : array{
        return $this->craftingRecipes;
    }

    public function getCraftingRecipe(int $networkId) : ?CraftingRecipe{
        return $this->craftingRecipes[$networkId] ?? null;
    }

    public function getCraftingRecipeById(string $id) : ?CraftingRecipe{
        return $this->recipesById[$id] ?? null;
    }

    /**
     * @return list<FurnaceRecipe>
     */
    public function getFurnaceRecipes() : array{
        return $this->furnaceRecipes;
    }

    /**
     * The recipe $block ("furnace", "blast_furnace", "smoker"...) uses to smelt $input, null for none.
     */
    public function getFurnaceRecipe(Item $input, string $block) : ?FurnaceRecipe{
        foreach($this->furnaceRecipes as $recipe){
            if(in_array($block, $recipe->getBlocks(), true) && $recipe->getInput()->matches($input)){
                return $recipe;
            }
        }

        return null;
    }

    /**
     * What brewing $reagent into $potion makes, null when it does nothing. A potion keeps its
     * kind (drinkable, splash, lingering) when mixed, container recipes change the kind.
     */
    public function getBrewingOutput(Item $potion, Item $reagent) : ?Item{
        if($potion->isNull() || $reagent->isNull()){
            return null;
        }

        if(in_array($potion->getId(), [ItemIds::POTION, ItemIds::SPLASH_POTION, ItemIds::LINGERING_POTION], true)){
            foreach($this->brewingRecipes as $recipe){
                if($recipe->getInputPotion() === $potion->getMeta() && $recipe->getReagent()->matches($reagent)){
                    return new Item($potion->getId(), 1, $recipe->getOutputPotion());
                }
            }
        }

        foreach($this->brewingContainerRecipes as $recipe){
            if($recipe->getInput() === $potion->getId() && $recipe->getReagent()->matches($reagent)){
                return new Item($recipe->getOutput(), 1, $potion->getMeta());
            }
        }

        return null;
    }

    /**
     * Whether the item is used in any brewing recipe.
     */
    public function isBrewingReagent(Item $item) : bool{
        foreach([...$this->brewingRecipes, ...$this->brewingContainerRecipes] as $recipe){
            if($recipe->getReagent()->matches($item)){
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<BrewingRecipe>
     */
    public function getBrewingRecipes() : array{
        return $this->brewingRecipes;
    }

    /**
     * @return list<BrewingContainerRecipe>
     */
    public function getBrewingContainerRecipes() : array{
        return $this->brewingContainerRecipes;
    }

    /**
     * @return array<int, SmithingRecipe> network ID => recipe
     */
    public function getSmithingRecipes() : array{
        return $this->smithingRecipes;
    }

    /**
     * Recipes a player unlocks by getting this item.
     *
     * @return list<CraftingRecipe>
     */
    public function getRecipesUnlockedBy(Item $item) : array{
        $recipes = [];

        foreach($this->unlockedByItem[$item->getId()] ?? [] as $recipe){
            foreach($recipe->getUnlock()?->getItems() ?? [] as $ingredient){
                if($ingredient->matches($item)){
                    $recipes[] = $recipe;
                    break;
                }
            }
        }

        return $recipes;
    }

    /**
     * Recipes unlocked in a situation, see RecipeUnlock::CONTEXT_*.
     *
     * @return list<CraftingRecipe>
     */
    public function getRecipesUnlockedIn(int $context) : array{
        return $this->unlockedByContext[$context] ?? [];
    }

    private function addCraftingRecipe(CraftingRecipe $recipe) : void{
        $this->craftingRecipes[$recipe->getNetworkId()] = $recipe;
        $this->recipesById[$recipe->getId()] = $recipe;

        $unlock = $recipe->getUnlock();

        if($unlock === null){
            return;
        }

        if($unlock->getContext() !== RecipeUnlock::CONTEXT_NONE){
            $this->unlockedByContext[$unlock->getContext()][] = $recipe;
            return;
        }

        foreach($unlock->getItems() as $ingredient){
            foreach($ingredient->getItemIds() as $id){
                $this->unlockedByItem[$id][] = $recipe;
            }
        }
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function ingredient(array $entry) : RecipeIngredient{
        if(isset($entry["tag"])){
            if(!is_string($entry["tag"]) || !isset($this->itemTags[$entry["tag"]])){
                throw new UnexpectedValueException("Unknown item tag in recipes");
            }

            return new RecipeIngredient(null, RecipeIngredient::ANY_META, $entry["tag"], $this->itemTags[$entry["tag"]], $entry["count"]);
        }

        return new RecipeIngredient($entry["item"], $entry["meta"], null, [], $entry["count"]);
    }

    /**
     * @param array<string, mixed> $entry
     */
    private function item(array $entry) : Item{
        return new Item($entry["item"], $entry["count"], $entry["meta"]);
    }

    /**
     * @param list<array<string, mixed>> $entries
     *
     * @return list<Item>
     */
    private function items(array $entries) : array{
        $items = [];

        foreach($entries as $entry){
            $items[] = $this->item($entry);
        }

        return $items;
    }

    /**
     * @param array<string, mixed>|null $entry
     */
    private function unlock(?array $entry) : ?RecipeUnlock{
        if($entry === null){
            return null;
        }

        $context = RecipeUnlock::CONTEXTS[$entry["context"]] ?? throw new UnexpectedValueException("Unknown recipe unlock context {$entry["context"]}");
        $items = [];

        foreach($entry["items"] as $item){
            $items[] = $this->ingredient($item);
        }

        return new RecipeUnlock($context, $items);
    }
}