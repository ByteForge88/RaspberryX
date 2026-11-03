<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\convert;

use raspberry\block\BlockStateRegistry;

use raspberry\crafting\CraftingManager;
use raspberry\crafting\CraftingRecipe;
use raspberry\crafting\RecipeIngredient;
use raspberry\crafting\ShapedRecipe;

use raspberry\inventory\CreativeInventory;

use raspberry\item\Item;
use raspberry\item\ItemIds;
use raspberry\item\ItemSerializer;

use raspberry\network\mcpe\protocol\CraftingDataPacket;
use raspberry\network\mcpe\protocol\CreativeContentPacket;
use raspberry\network\mcpe\protocol\types\recipe\CraftingRecipeData;
use raspberry\network\mcpe\protocol\types\recipe\ItemDescriptor;
use raspberry\network\mcpe\protocol\types\recipe\SmithingRecipeData;
use raspberry\network\mcpe\protocol\types\ItemStack;
use raspberry\network\mcpe\serializer\ItemStackSerializer;

use raspberry\utils\Uuid;

use function array_map;
use function count;
use function in_array;
use function max;

class ItemTranslator{

    private ItemTypeDictionary $dictionary;

    private BlockStateRegistry $blocks;

    private BlockTranslator $blockTranslator;

    /** @var array<string, int> */
    private array $blockIds = [];

    public function __construct(ItemTypeDictionary $dictionary, BlockStateRegistry $blocks, BlockTranslator $blockTranslator){
        $this->dictionary = $dictionary;
        $this->blocks = $blocks;
        $this->blockTranslator = $blockTranslator;

        ItemStackSerializer::setShieldRuntimeId($dictionary->getRuntimeId(ItemIds::SHIELD) ?? -1);
    }

    public function toNetwork(Item $item) : ItemStack{
        if($item->isNull()){
            return ItemStack::empty();
        }

        $runtimeId = $this->dictionary->getRuntimeId($item->getId());

        if($runtimeId === null){
            return ItemStack::empty();
        }

        return new ItemStack($runtimeId, $item->getCount(), $item->getMeta(), $this->getBlockNetworkId($item->getId()), ItemSerializer::serializeTag($item));
    }

    /**
     * @param list<Item>|null $items
     */
    public function buildCreativeContent(CreativeInventory $creative, ?array &$items = null) : CreativeContentPacket{
        $items = [];
        $packet = new CreativeContentPacket();
        $groups = [];

        foreach($creative->getEntries() as [$category, $item]){
            $stack = $this->toNetwork($item);

            if($stack->isEmpty()){
                continue;
            }

            if(!isset($groups[$category])){
                $groups[$category] = count($packet->groups);
                $packet->groups[] = [$category, "", ItemStack::empty()];
            }

            $packet->items[] = [$stack, $groups[$category]];
            $items[] = $item;
        }

        return $packet;
    }

    public function buildCraftingData(CraftingManager $crafting) : CraftingDataPacket{
        $packet = new CraftingDataPacket();

        foreach($crafting->getCraftingRecipes() as $recipe){
            $data = $this->toRecipeData($recipe);

            if($data === null){
                continue;
            }

            if($data->isShaped()){
                $packet->shaped[] = $data;
            }else{
                $packet->shapeless[] = $data;
            }
        }

        foreach($crafting->getSmithingRecipes() as $recipe){
            $output = $recipe->getOutput();
            $data = new SmithingRecipeData($recipe->getId(), $this->toDescriptor($recipe->getTemplate()), $this->toDescriptor($recipe->getBase()), $this->toDescriptor($recipe->getAddition()), $output === null ? null : $this->toNetwork($output), "smithing_table", $recipe->getNetworkId());

            if($recipe->isTrim()){
                $packet->smithingTrim[] = $data;
            }else{
                $packet->smithingTransform[] = $data;
            }
        }

        $potion = $this->dictionary->getRuntimeId(ItemIds::POTION) ?? 0;

        foreach($crafting->getBrewingRecipes() as $recipe){
            $reagent = $recipe->getReagent();
            $reagentId = $this->dictionary->getRuntimeId($reagent->getItemId() ?? "");

            if($reagentId !== null){
                $packet->potionRecipes[] = [$potion, $recipe->getInputPotion(), $reagentId, max(0, $reagent->getMeta()), $potion, $recipe->getOutputPotion()];
            }
        }

        foreach($crafting->getBrewingContainerRecipes() as $recipe){
            $ids = [$this->dictionary->getRuntimeId($recipe->getInput()), $this->dictionary->getRuntimeId($recipe->getReagent()->getItemId() ?? ""), $this->dictionary->getRuntimeId($recipe->getOutput())];

            if(!in_array(null, $ids, true)){
                $packet->potionContainerRecipes[] = $ids;
            }
        }

        return $packet;
    }

    private function toRecipeData(CraftingRecipe $recipe) : ?CraftingRecipeData{
        $output = [];

        foreach($recipe->getOutput() as $item){
            $stack = $this->toNetwork($item);

            if($stack->isEmpty()){
                return null;
            }

            $output[] = $stack;
        }

        $unlock = $recipe->getUnlock();
        $shaped = $recipe instanceof ShapedRecipe;
        $input = $shaped ? $recipe->getInput() : $recipe->getIngredients();

        return new CraftingRecipeData(
            $recipe->getId(),
            $shaped ? $recipe->getWidth() : 0,
            $shaped ? $recipe->getHeight() : 0,
            array_map(fn(?RecipeIngredient $ingredient) => $ingredient === null ? ItemDescriptor::empty() : $this->toDescriptor($ingredient), $input),
            $output,
            Uuid::random(),
            $recipe->getBlock(),
            $recipe->getPriority(),
            $unlock?->getContext(),
            array_map(fn(RecipeIngredient $ingredient) => $this->toDescriptor($ingredient), $unlock?->getItems() ?? []),
            $recipe->getNetworkId()
        );
    }

    private function toDescriptor(RecipeIngredient $ingredient) : ItemDescriptor{
        $meta = $ingredient->getMeta();

        return new ItemDescriptor($ingredient->getItemId(), $meta === RecipeIngredient::ANY_META ? ItemDescriptor::ANY_META : $meta, $ingredient->getTag(), $ingredient->getCount());
    }

    private function getBlockNetworkId(string $id) : int{
        if(!isset($this->blockIds[$id])){
            $this->blockIds[$id] = $this->blocks->isRegistered($id) ? $this->blockTranslator->getNetworkId($this->blocks->getDefaultState($id)) : 0;
        }

        return $this->blockIds[$id];
    }
}