<?php

declare(strict_types=1);

namespace raspberry\network\mcpe;

use raspberry\block\entity\Furnace;
use raspberry\block\entity\BrewingStand;

use raspberry\crafting\FurnaceData;
use raspberry\crafting\CraftingRecipe;

use raspberry\inventory\Inventory;
use raspberry\inventory\ArmorInventory;
use raspberry\inventory\PlayerInventory;

use raspberry\item\Item;
use raspberry\item\ItemIds;

use raspberry\network\mcpe\protocol\ItemStackRequestPacket;
use raspberry\network\mcpe\protocol\ItemStackResponsePacket;
use raspberry\network\mcpe\protocol\types\inventory\ContainerIds;
use raspberry\network\mcpe\protocol\types\inventory\ItemStackRequest;
use raspberry\network\mcpe\protocol\types\inventory\ItemStackResponse;
use raspberry\network\mcpe\protocol\types\inventory\StackRequestAction;
use raspberry\network\mcpe\protocol\types\inventory\StackRequestSlot;

use raspberry\player\Player;
use raspberry\player\GameMode;

use raspberry\Server;

use UnexpectedValueException;

use function array_key_first;
use function count;
use function in_array;
use function min;
use function preg_match;
use function spl_object_id;

class ItemStackRequestHandler{

    /** how many past requests are remembered for the stack IDs the client names after them */
    private const REMEMBERED_REQUESTS = 32;

    /** items allowed in the offhand */
    private const OFFHAND_ITEMS = [
        ItemIds::SHIELD,
        ItemIds::TOTEM_OF_UNDYING,
        ItemIds::ARROW,
        ItemIds::FIREWORK_ROCKET,
        ItemIds::NAUTILUS_SHELL,
        ItemIds::FILLED_MAP,
        ItemIds::EMPTY_MAP
    ];

    private NetworkSession $session;

    private Player $player;

    private InventoryManager $inventoryManager;

    private int $requestId = 0;

    /** @var array<string, array{Inventory, int, Item, int, int, int}> */
    private array $changes = [];

    /** @var array<int, array<string, int>> */
    private array $requestStacks = [];

    /** @var list<Item> items the running request drops, thrown once it succeeded */
    private array $drops = [];

    /** what the running request made (crafted or taken from the creative menu), not in any slot yet */
    private ?Item $created = null;

    /** how many of $created are left; can be more than a stack after shift-click crafting */
    private int $createdCount = 0;

    /** @var list<array{Item, int}> outputs of a recipe with more than one, waiting for a create action */
    private array $pendingResults = [];

    /** the 3x3 grid of a crafting table instead of the 2x2 grid of the inventory */
    private bool $largeGrid = false;

    /** @var list<string> text sent with the running request */
    private array $filterStrings = [];

    private WorkBlockRequests $workBlocks;

    public function __construct(NetworkSession $session, Player $player, InventoryManager $inventoryManager){
        $this->session = $session;
        $this->player = $player;
        $this->inventoryManager = $inventoryManager;
        $this->workBlocks = new WorkBlockRequests($this, $session, $player);
    }

    /**
     * Uses the 3x3 grid of a crafting table, while one is open.
     */
    public function setLargeGrid(bool $largeGrid) : void{
        $this->largeGrid = $largeGrid;
    }

    public function handle(ItemStackRequestPacket $packet) : void{
        $this->handleRequests($packet->requests);
    }

    /**
     * @param list<ItemStackRequest> $requests
     */
    public function handleRequests(array $requests) : void{
        $responses = [];
        $rejected = false;

        foreach($requests as $request){
            $response = $this->handleRequest($request);
            $rejected = $rejected || $response->status !== ItemStackResponse::STATUS_OK;
            $responses[] = $response;
        }

        $this->session->sendDataPacket(ItemStackResponsePacket::create($responses));

        if($rejected){
            $this->inventoryManager->sendAll();
        }

        $this->workBlocks->updateEnchantOffers();
    }

    /**
     * Changes a work block slot (anvil, loom...) as part of the running request.
     */
    public function setUIItem(int $containerId, int $uiSlot, Item $item) : void{
        $this->setItem(new StackRequestSlot($containerId, null, $uiSlot, 0), $item);
    }

    /**
     * Puts what a work block made into the created output slot.
     */
    public function createResult(Item $item) : void{
        $this->created = (clone $item)->setCount(1);
        $this->createdCount = $item->getCount();
    }

    private function handleRequest(ItemStackRequest $request) : ItemStackResponse{
        $this->requestId = $request->requestId;
        $this->changes = [];
        $this->drops = [];
        $this->created = null;
        $this->createdCount = 0;
        $this->pendingResults = [];
        $this->requestStacks[$request->requestId] = [];
        $this->filterStrings = $request->filterStrings;

        while(count($this->requestStacks) > self::REMEMBERED_REQUESTS){
            unset($this->requestStacks[array_key_first($this->requestStacks)]);
        }

        $this->inventoryManager->setQuiet(true);

        try{
            foreach($request->actions as $action){
                $this->handleAction($action);
            }

            $response = new ItemStackResponse(ItemStackResponse::STATUS_OK, $request->requestId, $this->buildChanges());
            $this->collectFurnaceExperience($request);
            $this->workBlocks->onRequestDone(true);
        }catch(UnexpectedValueException $exception){
            $this->undo();
            $this->workBlocks->onRequestDone(false);
            $this->drops = [];
            $this->session->getServer()->getLogger()->debug("Rejected inventory request {$request->requestId} of {$this->player->getName()}: " . $exception->getMessage());
            $response = new ItemStackResponse(ItemStackResponse::STATUS_ERROR, $request->requestId);
        }finally{
            //whatever was made for the request and not taken out of the output slot is gone
            $this->created = null;
            $this->pendingResults = [];
            $this->inventoryManager->setQuiet(false);
        }

        foreach($this->changes as [$inventory, $slot]){
            $this->inventoryManager->onRequestChange($inventory, $slot);
        }

        foreach($this->drops as $item){
            $this->player->dropItem($item);
        }

        $this->changes = [];
        $this->drops = [];

        return $response;
    }

    /**
     * @throws UnexpectedValueException
     */
    private function handleAction(StackRequestAction $action) : void{
        if($this->workBlocks->handleAction($action, $this->filterStrings)){
            return;
        }

        match($action->type){
            StackRequestAction::TAKE, StackRequestAction::PLACE => $this->transfer($this->slot($action->source), $this->slot($action->destination), $action->count),
            StackRequestAction::SWAP => $this->swap($this->slot($action->source), $this->slot($action->destination)),
            StackRequestAction::DESTROY => $this->destroy($this->slot($action->source), $action->count),
            StackRequestAction::CRAFT_CREATIVE => $this->craftCreative($action->creativeItemId),
            StackRequestAction::CRAFT_RECIPE => $this->craftRecipe($action->recipeNetworkId, $action->timesCrafted, false),
            StackRequestAction::CRAFT_RECIPE_AUTO => $this->craftRecipe($action->recipeNetworkId, $action->timesCrafted, true),
            StackRequestAction::CREATE => $this->create($action->resultsSlot),
            //the server already took the ingredients and knows the results
            StackRequestAction::CONSUME, StackRequestAction::CRAFT_RESULTS_DEPRECATED => null,
            StackRequestAction::MINE_BLOCK => $this->setItem($action->source ?? throw new UnexpectedValueException("Missing slot"), $this->getItem($action->source)),
            StackRequestAction::DROP => $this->drop($this->slot($action->source), $action->count),
            default => throw new UnexpectedValueException("Inventory action {$action->type} is not supported")
        };
    }

    private function transfer(StackRequestSlot $from, StackRequestSlot $to, int $count) : void{
        if($to->containerId === ContainerIds::CREATED_OUTPUT){
            throw new UnexpectedValueException("Items cannot be put into the created output slot");
        }

        $fromCreated = $from->containerId === ContainerIds::CREATED_OUTPUT;
        $source = $fromCreated ? $this->takeCreated($count) : $this->getItem($from);
        $destination = $this->getItem($to);

        if($count < 1 || $source->isNull() || $source->getCount() < $count){
            throw new UnexpectedValueException("Cannot take {$count} items from a slot holding " . $source->getCount());
        }

        if(!$destination->isNull() && !$destination->canStackWith($source)){
            throw new UnexpectedValueException("Cannot put " . $source->getId() . " onto " . $destination->getId());
        }

        $moved = (clone $source)->setCount($destination->getCount() + $count);
        $this->checkFits($to, $moved);

        if($moved->getCount() > $moved->getMaxStackSize()){
            throw new UnexpectedValueException("The stack would hold more than " . $moved->getMaxStackSize());
        }

        if(!$fromCreated){
            $this->setItem($from, (clone $source)->setCount($source->getCount() - $count));
        }

        $this->setItem($to, $moved);
    }

    private function drop(StackRequestSlot $slot, int $count) : void{
        if($slot->containerId === ContainerIds::CREATED_OUTPUT){
            $this->drops[] = $this->takeCreated($count);
            return;
        }

        $item = $this->getItem($slot);

        if($count < 1 || $item->getCount() < $count){
            throw new UnexpectedValueException("Cannot drop {$count} items from a slot holding " . $item->getCount());
        }

        $this->setItem($slot, (clone $item)->setCount($item->getCount() - $count));
        $this->drops[] = (clone $item)->setCount($count);
    }

    private function swap(StackRequestSlot $a, StackRequestSlot $b) : void{
        $first = $this->getItem($a);
        $second = $this->getItem($b);

        if($a->containerId === ContainerIds::CREATED_OUTPUT || $b->containerId === ContainerIds::CREATED_OUTPUT){
            throw new UnexpectedValueException("Items cannot be swapped with the created output slot");
        }

        $this->checkFits($a, $second);
        $this->checkFits($b, $first);

        $this->setItem($a, $second);
        $this->setItem($b, $first);
    }

    private function destroy(StackRequestSlot $slot, int $count) : void{
        if($this->player->getGameMode() !== GameMode::CREATIVE){
            throw new UnexpectedValueException("Only creative players can delete items");
        }

        if($slot->containerId === ContainerIds::CREATED_OUTPUT){
            $this->takeCreated($count);
            return;
        }

        $item = $this->getItem($slot);

        if($count < 1 || $item->getCount() < $count){
            throw new UnexpectedValueException("Cannot delete {$count} items from a slot holding " . $item->getCount());
        }

        $this->setItem($slot, (clone $item)->setCount($item->getCount() - $count));
    }

    private function craftCreative(int $creativeItemId) : void{
        if($this->player->getGameMode() !== GameMode::CREATIVE){
            throw new UnexpectedValueException("Only creative players can take creative items");
        }

        $item = Server::getInstance()->getCreativeItem($creativeItemId) ?? throw new UnexpectedValueException("There is no creative item {$creativeItemId}");

        $this->created = $item->setCount(1);
        $this->createdCount = $item->getMaxStackSize();
    }

    private function craftRecipe(int $networkId, int $times, bool $auto) : void{
        $recipe = Server::getInstance()->getCraftingManager()->getCraftingRecipe($networkId) ?? throw new UnexpectedValueException("There is no crafting recipe {$networkId}");

        if($recipe->getBlock() !== $this->workBlocks->getRecipeBlock()){
            throw new UnexpectedValueException("Recipe {$recipe->getId()} needs a " . $recipe->getBlock());
        }

        if($times < 1){
            throw new UnexpectedValueException("A recipe must be crafted at least once");
        }

        if($recipe->getBlock() !== CraftingRecipe::BLOCK_CRAFTING_TABLE){
            $this->consumeWorkBlock($recipe, $times);
        }elseif($auto){
            if(!$this->player->getRecipeBook()->isUnlocked($recipe)){
                throw new UnexpectedValueException("Recipe {$recipe->getId()} is not unlocked");
            }

            $this->consumeAnywhere($recipe, $times);
        }else{
            $this->consumeGrid($recipe, $times);
        }

        foreach($recipe->getOutput() as $item){
            $this->pendingResults[] = [$item, $item->getCount() * $times];
        }

        //with one output it goes straight to the created output slot, with more the client picks
        if(count($this->pendingResults) === 1){
            $this->create(0);
        }
    }

    /**
     * Moves one output of the crafted recipe to the created output slot.
     */
    private function create(int $resultsSlot) : void{
        [$item, $count] = $this->pendingResults[$resultsSlot] ?? throw new UnexpectedValueException("There is no crafting result {$resultsSlot}");
        unset($this->pendingResults[$resultsSlot]);

        $this->created = $item->setCount(1);
        $this->createdCount = $count;
    }

    private function consumeGrid(CraftingRecipe $recipe, int $times) : void{
        [$offset, $size] = $this->getCraftingGrid();
        $ui = $this->player->getUIInventory();
        $grid = [];

        for($index = 0; $index < $size * $size; ++$index){
            $grid[] = $ui->getItem($offset + $index);
        }

        $matched = $recipe->match($grid, $size, $times) ?? throw new UnexpectedValueException("The crafting grid does not hold recipe {$recipe->getId()} x{$times}");

        foreach($matched as $index => $ingredient){
            $item = $grid[$index];
            $this->setItem(new StackRequestSlot(ContainerIds::CRAFTING_INPUT, null, $offset + $index, 0), $item->setCount($item->getCount() - $ingredient->getCount() * $times));
        }
    }

    /**
     * Takes the ingredients of a stonecutter or cartography table recipe from its input slots.
     */
    private function consumeWorkBlock(CraftingRecipe $recipe, int $times) : void{
        $slots = $this->workBlocks->getRecipeSlots();
        $left = $slots;

        foreach($recipe->getIngredients() as $ingredient){
            $needed = $ingredient->getCount() * $times;

            foreach($left as $key => [$containerId, $uiSlot]){
                $item = $this->player->getUIInventory()->getItem($uiSlot);

                if($ingredient->matches($item) && $item->getCount() >= $needed){
                    $this->setUIItem($containerId, $uiSlot, (clone $item)->setCount($item->getCount() - $needed));
                    unset($left[$key]);
                    continue 2;
                }
            }

            throw new UnexpectedValueException("The input slots do not hold recipe {$recipe->getId()} x{$times}");
        }

        foreach($left as [, $uiSlot]){
            if(!$this->player->getUIInventory()->getItem($uiSlot)->isNull()){
                throw new UnexpectedValueException("Recipe {$recipe->getId()} does not use the item in slot {$uiSlot}");
            }
        }
    }

    /**
     * Takes every ingredient from the crafting grid first, then from the inventory.
     */
    private function consumeAnywhere(CraftingRecipe $recipe, int $times) : void{
        [$offset, $size] = $this->getCraftingGrid();
        $slots = [];

        for($index = 0; $index < $size * $size; ++$index){
            $slots[] = new StackRequestSlot(ContainerIds::CRAFTING_INPUT, null, $offset + $index, 0);
        }

        for($index = 0; $index < PlayerInventory::SIZE; ++$index){
            $slots[] = new StackRequestSlot(ContainerIds::COMBINED_HOTBAR_AND_INVENTORY, null, $index, 0);
        }

        foreach($recipe->getIngredients() as $ingredient){
            $needed = $ingredient->getCount() * $times;

            foreach($slots as $slot){
                $item = $this->getItem($slot);

                if(!$ingredient->matches($item)){
                    continue;
                }

                $taken = min($needed, $item->getCount());
                $this->setItem($slot, $item->setCount($item->getCount() - $taken));
                $needed -= $taken;

                if($needed === 0){
                    break;
                }
            }

            if($needed > 0){
                throw new UnexpectedValueException("Not enough items for recipe {$recipe->getId()} x{$times}");
            }
        }
    }

    /**
     * Takes items out of the created output slot.
     */
    private function takeCreated(int $count) : Item{
        if($this->created === null || $count < 1 || $count > $this->createdCount){
            throw new UnexpectedValueException("Cannot take {$count} items from the created output, it holds {$this->createdCount}");
        }

        $this->createdCount -= $count;

        return (clone $this->created)->setCount($count);
    }

    /**
     * @return array{int, int} first UI slot of the crafting grid, and its width
     */
    private function getCraftingGrid() : array{
        return $this->largeGrid ? [Player::UI_CRAFTING_LARGE, 3] : [Player::UI_CRAFTING_SMALL, 2];
    }

    /**
     * Taking from a furnace's output gives the player the experience it stored.
     */
    private function collectFurnaceExperience(ItemStackRequest $request) : void{
        $furnace = $this->inventoryManager->getWindow()?->getEntity();

        if(!$furnace instanceof Furnace){
            return;
        }

        foreach($request->actions as $action){
            if($action->source?->containerId === ContainerIds::FURNACE_RESULT){
                $this->player->addXp($furnace->collectExperience());
                return;
            }
        }
    }

    private function checkBlockSlot(StackRequestSlot $slot, Item $item) : void{
        $window = $this->inventoryManager->getWindow();
        $resolved = $window?->resolve($slot->containerId, $slot->slot);
        $entity = $window?->getEntity();

        if($resolved === null){
            return;
        }

        $index = $resolved[1];

        $fits = match(true){
            $entity instanceof Furnace => match($index){
                Furnace::SLOT_OUTPUT => false,
                Furnace::SLOT_FUEL => FurnaceData::getBurnTicks($item) > 0 || $item->getId() === ItemIds::BUCKET,
                default => true
            },
            $entity instanceof BrewingStand => match(true){
                $index === BrewingStand::SLOT_FUEL => $item->getId() === ItemIds::BLAZE_POWDER,
                $index === BrewingStand::SLOT_REAGENT => $this->session->getServer()->getCraftingManager()->isBrewingReagent($item),
                default => $item->getCount() === 1 && in_array($item->getId(), [ItemIds::POTION, ItemIds::SPLASH_POTION, ItemIds::LINGERING_POTION, ItemIds::GLASS_BOTTLE], true)
            },
            default => true
        };

        if(!$fits){
            throw new UnexpectedValueException($item->getId() . " cannot go in slot {$index} of " . $entity?->getSaveId());
        }
    }

    private function checkFits(StackRequestSlot $slot, Item $item) : void{
        if($item->isNull()){
            return;
        }

        $this->checkBlockSlot($slot, $item);

        if($slot->containerId === ContainerIds::ARMOR){
            if($item->getCount() > 1 || !self::isArmorFor($slot->slot, $item->getId())){
                throw new UnexpectedValueException($item->getId() . " cannot go in armor slot {$slot->slot}");
            }
        }elseif($slot->containerId === ContainerIds::SHULKER_BOX && preg_match('/^minecraft:\w+_shulker_box$/D', $item->getId()) === 1){
            throw new UnexpectedValueException("A shulker box cannot hold a shulker box");
        }elseif($slot->containerId === ContainerIds::OFFHAND && !in_array($item->getId(), self::OFFHAND_ITEMS, true)){
            throw new UnexpectedValueException($item->getId() . " cannot go in the offhand");
        }
    }

    private static function isArmorFor(int $slot, string $id) : bool{
        return match($slot){
            ArmorInventory::SLOT_HEAD => preg_match('/^minecraft:(\w+_helmet|carved_pumpkin|\w+_head|\w+_skull)$/D', $id) === 1,
            ArmorInventory::SLOT_CHEST => preg_match('/^minecraft:(\w+_chestplate|elytra)$/D', $id) === 1,
            ArmorInventory::SLOT_LEGS => preg_match('/^minecraft:\w+_leggings$/D', $id) === 1,
            ArmorInventory::SLOT_FEET => preg_match('/^minecraft:\w+_boots$/D', $id) === 1,
            default => false
        };
    }

    /**
     * @return array{Inventory, int}
     */
    private function resolve(StackRequestSlot $slot) : array{
        $player = $this->player;

        $resolved = match($slot->containerId){
            ContainerIds::HOTBAR => $slot->slot < PlayerInventory::HOTBAR_SIZE ? [$player->getInventory(), $slot->slot] : null,
            ContainerIds::INVENTORY, ContainerIds::COMBINED_HOTBAR_AND_INVENTORY => $slot->slot < PlayerInventory::SIZE ? [$player->getInventory(), $slot->slot] : null,
            ContainerIds::ARMOR => $slot->slot < 4 ? [$player->getArmorInventory(), $slot->slot] : null,
            ContainerIds::OFFHAND => $slot->slot <= 1 ? [$player->getOffHandInventory(), 0] : null,
            ContainerIds::CURSOR => $slot->slot === Player::UI_CURSOR ? [$player->getUIInventory(), Player::UI_CURSOR] : null,
            ContainerIds::CRAFTING_INPUT => $this->isCraftingSlot($slot->slot) ? [$player->getUIInventory(), $slot->slot] : null,
            ContainerIds::LEVEL_ENTITY,
            ContainerIds::SHULKER_BOX,
            ContainerIds::BARREL,
            ContainerIds::FURNACE_INGREDIENT,
            ContainerIds::BLAST_FURNACE_INGREDIENT,
            ContainerIds::SMOKER_INGREDIENT,
            ContainerIds::FURNACE_FUEL,
            ContainerIds::FURNACE_RESULT,
            ContainerIds::BREWING_STAND_INPUT,
            ContainerIds::BREWING_STAND_RESULT,
            ContainerIds::BREWING_STAND_FUEL => $this->inventoryManager->getWindow()?->resolve($slot->containerId, $slot->slot),
            default => $this->resolveWorkBlock($slot)
        };

        return $resolved ?? throw new UnexpectedValueException("Container {$slot->containerId} slot {$slot->slot} cannot be used");
    }

    /**
     * @return array{Inventory, int}|null
     */
    private function resolveWorkBlock(StackRequestSlot $slot) : ?array{
        $uiSlot = $this->workBlocks->resolveSlot($slot->containerId, $slot->slot);

        return $uiSlot === null ? null : [$this->player->getUIInventory(), $uiSlot];
    }

    private function isCraftingSlot(int $slot) : bool{
        [$offset, $size] = $this->getCraftingGrid();

        return $slot >= $offset && $slot < $offset + $size * $size;
    }

    private function slot(?StackRequestSlot $slot) : StackRequestSlot{
        if($slot === null){
            throw new UnexpectedValueException("Missing slot");
        }

        if($slot->containerId === ContainerIds::CREATED_OUTPUT){
            return $slot;
        }

        [$inventory, $index] = $this->resolve($slot);
        $expected = $this->inventoryManager->getStackId($inventory, $index);
        $named = $slot->stackNetworkId;

        //a negative ID names the stack an earlier request (with that ID) left in the slot
        if($named < 0){
            $named = $this->requestStacks[$named][self::key($inventory, $index)] ?? throw new UnexpectedValueException("Slot was not changed by request {$named}");
        }

        if($named !== $expected){
            throw new UnexpectedValueException("Stack ID mismatch in container {$slot->containerId} slot {$slot->slot}: client {$named}, server {$expected}");
        }

        return $slot;
    }

    private function getItem(StackRequestSlot $slot) : Item{
        [$inventory, $index] = $this->resolve($slot);

        return $inventory->getItem($index);
    }

    private function setItem(StackRequestSlot $slot, Item $item) : void{
        [$inventory, $index] = $this->resolve($slot);
        $key = self::key($inventory, $index);

        if(!isset($this->changes[$key])){
            $this->changes[$key] = [$inventory, $index, $inventory->getItem($index), $this->inventoryManager->getStackId($inventory, $index), $slot->containerId, $slot->slot];
        }

        $inventory->setItem($index, $item);
        $this->requestStacks[$this->requestId][$key] = $this->inventoryManager->getStackId($inventory, $index);
    }

    private function undo() : void{
        foreach($this->changes as [$inventory, $index, $before, $stackId]){
            $inventory->setItem($index, $before);
            $this->inventoryManager->setStackId($inventory, $index, $stackId);
        }

        unset($this->requestStacks[$this->requestId]);
    }

    /**
     * @return array<int, list<array{int, int, int, string, int}>>
     */
    private function buildChanges() : array{
        $containers = [];

        foreach($this->changes as [$inventory, $index, , , $containerId, $clientSlot]){
            $item = $inventory->getItem($index);

            $containers[$containerId][] = [
                $clientSlot,
                $item->getCount(),
                $this->inventoryManager->getStackId($inventory, $index),
                $item->getCustomName() ?? "",
                $item->getDamage()
            ];
        }

        return $containers;
    }

    private static function key(Inventory $inventory, int $index) : string{
        return spl_object_id($inventory) . ":" . $index;
    }
}