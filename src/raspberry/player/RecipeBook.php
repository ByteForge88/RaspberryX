<?php

declare(strict_types=1);

namespace raspberry\player;

use raspberry\crafting\CraftingManager;
use raspberry\crafting\CraftingRecipe;
use raspberry\crafting\RecipeUnlock;

use raspberry\inventory\Inventory;
use raspberry\inventory\InventoryListener;

use raspberry\item\Item;

use raspberry\network\mcpe\protocol\UnlockedRecipesPacket;

use function array_keys;
use function count;

class RecipeBook implements InventoryListener{

    /** filled inventory slots that count as "many items" (unlocks the chest) */
    public const MANY_ITEMS_SLOTS = 10;

    private const CONTEXT_CHECK_TICKS = 20;

    private Player $player;

    private CraftingManager $crafting;

    /** @var array<string, true> */
    private array $unlocked = [];

    /** @var array<string, true> */
    private array $pending = [];

    private bool $sent = false;

    public function __construct(Player $player, CraftingManager $crafting){
        $this->player = $player;
        $this->crafting = $crafting;

        $this->unlockAll($crafting->getRecipesUnlockedIn(RecipeUnlock::CONTEXT_ALWAYS_UNLOCKED));
    }

    /**
     * Recipes without unlock rules are always shown.
     */
    public function isUnlocked(CraftingRecipe $recipe) : bool{
        return $recipe->getUnlock() === null || isset($this->unlocked[$recipe->getId()]);
    }

    /**
     * @return list<string> recipe IDs
     */
    public function getUnlocked() : array{
        return array_keys($this->unlocked);
    }

    /**
     * @param list<string> $ids
     */
    public function load(array $ids) : void{
        foreach($ids as $id){
            if($this->crafting->getCraftingRecipeById($id) !== null){
                $this->unlocked[$id] = true;
            }
        }
    }

    /**
     * @param list<CraftingRecipe> $recipes
     */
    public function unlockAll(array $recipes) : void{
        foreach($recipes as $recipe){
            if(!isset($this->unlocked[$recipe->getId()])){
                $this->unlocked[$recipe->getId()] = true;
                $this->pending[$recipe->getId()] = true;
            }
        }
    }

    /**
     * Sends the whole recipe book (on join), after that only what unlocks.
     */
    public function sendAll() : void{
        $this->sent = true;
        $this->pending = [];
        $this->player->getNetworkSession()->sendDataPacket(UnlockedRecipesPacket::create(UnlockedRecipesPacket::TYPE_INITIALLY_UNLOCKED, $this->getUnlocked()));
    }

    public function tick(int $currentTick) : void{
        if($currentTick % self::CONTEXT_CHECK_TICKS === 0){
            if($this->player->isInWater()){
                $this->unlockAll($this->crafting->getRecipesUnlockedIn(RecipeUnlock::CONTEXT_PLAYER_IN_WATER));
            }

            if(count($this->player->getInventory()->getContents()) >= self::MANY_ITEMS_SLOTS){
                $this->unlockAll($this->crafting->getRecipesUnlockedIn(RecipeUnlock::CONTEXT_PLAYER_HAS_MANY_ITEMS));
            }
        }

        if($this->sent && $this->pending !== []){
            $this->player->getNetworkSession()->sendDataPacket(UnlockedRecipesPacket::create(UnlockedRecipesPacket::TYPE_NEWLY_UNLOCKED, array_keys($this->pending)));
            $this->pending = [];
        }
    }

    public function onSlotChange(Inventory $inventory, int $slot, Item $oldItem) : void{
        $item = $inventory->getItem($slot);

        if(!$item->isNull() && ($oldItem->isNull() || $oldItem->getId() !== $item->getId() || $oldItem->getMeta() !== $item->getMeta())){
            $this->unlockAll($this->crafting->getRecipesUnlockedBy($item));
        }
    }

    public function onContentChange(Inventory $inventory) : void{
        foreach($inventory->getContents() as $item){
            $this->unlockAll($this->crafting->getRecipesUnlockedBy($item));
        }
    }
}