<?php

declare(strict_types=1);

namespace raspberry\network\mcpe;

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

    /** @var list<Item> */
    private array $drops = [];

    public function __construct(NetworkSession $session, Player $player, InventoryManager $inventoryManager){
        $this->session = $session;
        $this->player = $player;
        $this->inventoryManager = $inventoryManager;
    }

    public function handle(ItemStackRequestPacket $packet) : void{
        $responses = [];
        $rejected = false;

        foreach($packet->requests as $request){
            $response = $this->handleRequest($request);
            $rejected = $rejected || $response->status !== ItemStackResponse::STATUS_OK;
            $responses[] = $response;
        }

        $this->session->sendDataPacket(ItemStackResponsePacket::create($responses));

        if($rejected){
            $this->inventoryManager->sendAll();
        }
    }

    private function handleRequest(ItemStackRequest $request) : ItemStackResponse{
        $this->requestId = $request->requestId;
        $this->changes = [];
        $this->drops = [];
        $this->requestStacks[$request->requestId] = [];

        while(count($this->requestStacks) > self::REMEMBERED_REQUESTS){
            unset($this->requestStacks[array_key_first($this->requestStacks)]);
        }

        $this->inventoryManager->setQuiet(true);

        try{
            foreach($request->actions as $action){
                $this->handleAction($action);
            }

            $response = new ItemStackResponse(ItemStackResponse::STATUS_OK, $request->requestId, $this->buildChanges());
        }catch(UnexpectedValueException $exception){
            $this->undo();
            $this->drops = [];
            $this->session->getServer()->getLogger()->debug("Rejected inventory request {$request->requestId} of {$this->player->getName()}: " . $exception->getMessage());
            $response = new ItemStackResponse(ItemStackResponse::STATUS_ERROR, $request->requestId);
        }finally{
            //whatever was made for the request and not taken out of the output slot is gone
            $this->player->getUIInventory()->clear(Player::UI_CREATED_OUTPUT);
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
        match($action->type){
            StackRequestAction::TAKE, StackRequestAction::PLACE => $this->transfer($this->slot($action->source), $this->slot($action->destination), $action->count),
            StackRequestAction::SWAP => $this->swap($this->slot($action->source), $this->slot($action->destination)),
            StackRequestAction::DESTROY => $this->destroy($this->slot($action->source), $action->count),
            StackRequestAction::CRAFT_CREATIVE => $this->craftCreative($action->creativeItemId),
            StackRequestAction::CRAFT_RESULTS_DEPRECATED => null,
            StackRequestAction::DROP => $this->drop($this->slot($action->source), $action->count),
            default => throw new UnexpectedValueException("Inventory action {$action->type} is not supported")
        };
    }

    private function transfer(StackRequestSlot $from, StackRequestSlot $to, int $count) : void{
        $source = $this->getItem($from);
        $destination = $this->getItem($to);

        if($count < 1 || $source->isNull() || $source->getCount() < $count){
            throw new UnexpectedValueException("Cannot take {$count} items from a slot holding " . $source->getCount());
        }

        if($to->containerId === ContainerIds::CREATED_OUTPUT){
            throw new UnexpectedValueException("Items cannot be put into the created output slot");
        }

        if(!$destination->isNull() && !$destination->canStackWith($source)){
            throw new UnexpectedValueException("Cannot put " . $source->getId() . " onto " . $destination->getId());
        }

        $moved = (clone $source)->setCount($destination->getCount() + $count);
        $this->checkFits($to, $moved);

        if($moved->getCount() > $moved->getMaxStackSize()){
            throw new UnexpectedValueException("The stack would hold more than " . $moved->getMaxStackSize());
        }

        $this->setItem($from, (clone $source)->setCount($source->getCount() - $count));
        $this->setItem($to, $moved);
    }

    private function drop(StackRequestSlot $slot, int $count) : void{
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
        $item = (clone $item)->setCount($item->getMaxStackSize());

        $output = new StackRequestSlot(ContainerIds::CREATED_OUTPUT, null, Player::UI_CREATED_OUTPUT, 0);
        $this->setItem($output, $item);
    }

    private function checkFits(StackRequestSlot $slot, Item $item) : void{
        if($item->isNull()){
            return;
        }

        if($slot->containerId === ContainerIds::ARMOR){
            if($item->getCount() > 1 || !self::isArmorFor($slot->slot, $item->getId())){
                throw new UnexpectedValueException($item->getId() . " cannot go in armor slot {$slot->slot}");
            }
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
            ContainerIds::CREATED_OUTPUT => $slot->slot === Player::UI_CREATED_OUTPUT ? [$player->getUIInventory(), Player::UI_CREATED_OUTPUT] : null,
            default => null
        };

        return $resolved ?? throw new UnexpectedValueException("Container {$slot->containerId} slot {$slot->slot} cannot be used");
    }

    private function slot(?StackRequestSlot $slot) : StackRequestSlot{
        if($slot === null){
            throw new UnexpectedValueException("Missing slot");
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
            if($containerId === ContainerIds::CREATED_OUTPUT){
                continue; //emptied after every request
            }

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