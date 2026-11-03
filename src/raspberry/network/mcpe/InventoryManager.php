<?php

declare(strict_types=1);

namespace raspberry\network\mcpe;

use raspberry\inventory\Inventory;
use raspberry\inventory\InventoryListener;

use raspberry\item\Item;

use raspberry\network\mcpe\protocol\InventoryContentPacket;
use raspberry\network\mcpe\protocol\InventorySlotPacket;
use raspberry\network\mcpe\protocol\PlayerHotbarPacket;
use raspberry\network\mcpe\protocol\types\ItemInstance;

use raspberry\player\Player;

use function spl_object_id;

class InventoryManager implements InventoryListener{

    private NetworkSession $session;

    private Player $player;

    /** @var array<int, Inventory> */
    private array $inventories = [];

    /** @var array<int, int> */
    private array $windows = [];

    /** @var array<int, array<int, int>> */
    private array $stackIds = [];

    private int $nextStackId = 1;

    private bool $quiet = false;

    public function __construct(NetworkSession $session, Player $player){
        $this->session = $session;
        $this->player = $player;

        $this->addWindow($player->getInventory(), InventoryContentPacket::WINDOW_INVENTORY);
        $this->addWindow($player->getArmorInventory(), InventoryContentPacket::WINDOW_ARMOR);
        $this->addWindow($player->getOffHandInventory(), InventoryContentPacket::WINDOW_OFFHAND);
        $this->addWindow($player->getUIInventory(), InventoryContentPacket::WINDOW_UI);

        $player->getInventory()->addHeldSlotListener(function(int $slot) : void{
            $this->player->broadcastHeldItem();
        });
    }

    public function sendAll() : void{
        foreach($this->inventories as $inventory){
            $this->sendContents($inventory);
        }

        $this->session->sendDataPacket(PlayerHotbarPacket::create($this->player->getInventory()->getHeldSlot()));
    }

    public function getStackId(Inventory $inventory, int $slot) : int{
        return $this->stackIds[spl_object_id($inventory)][$slot] ?? 0;
    }

    /**
     * Sends one slot again, when the client asks for it.
     */
    public function sendSlot(Inventory $inventory, int $slot) : void{
        $key = spl_object_id($inventory);

        if(isset($this->windows[$key])){
            $this->session->sendDataPacket(InventorySlotPacket::create($this->windows[$key], $slot, $this->toInstance($inventory, $slot, $inventory->getItem($slot))));
        }
    }

    public function setStackId(Inventory $inventory, int $slot, int $stackId) : void{
        $this->stackIds[spl_object_id($inventory)][$slot] = $stackId;
    }

    public function setQuiet(bool $quiet) : void{
        $this->quiet = $quiet;
    }

    public function onSlotChange(Inventory $inventory, int $slot, Item $oldItem) : void{
        $key = spl_object_id($inventory);

        if(!isset($this->windows[$key])){
            return;
        }

        $item = $inventory->getItem($slot);

        if($item->isNull()){
            $this->stackIds[$key][$slot] = 0;
        }elseif($oldItem->isNull() || !$oldItem->canStackWith($item) || $this->getStackId($inventory, $slot) === 0){
            $this->stackIds[$key][$slot] = $this->nextStackId++;
        }

        if($this->quiet || !$this->player->isSpawned()){
            return;
        }

        $this->session->sendDataPacket(InventorySlotPacket::create($this->windows[$key], $slot, $this->toInstance($inventory, $slot, $item)));
        $this->onVisibleChange($inventory, $slot);
    }

    public function onContentChange(Inventory $inventory) : void{
        $key = spl_object_id($inventory);

        if(!isset($this->windows[$key])){
            return;
        }

        $this->stackIds[$key] = [];

        foreach($inventory->getContents() as $slot => $item){
            $this->stackIds[$key][$slot] = $this->nextStackId++;
        }

        if($this->quiet || !$this->player->isSpawned()){
            return;
        }

        $this->sendContents($inventory);
        $this->onVisibleChange($inventory, null);
    }

    public function onRequestChange(Inventory $inventory, int $slot) : void{
        $this->onVisibleChange($inventory, $slot);
    }

    private function addWindow(Inventory $inventory, int $windowId) : void{
        $key = spl_object_id($inventory);
        $this->inventories[$key] = $inventory;
        $this->windows[$key] = $windowId;
        $inventory->addListener($this);

        $this->onContentChange($inventory);
    }

    private function toInstance(Inventory $inventory, int $slot, Item $item) : ItemInstance{
        $instance = $this->session->toItemInstance($item);
        $instance->stackNetworkId = $instance->stack->isEmpty() ? 0 : $this->getStackId($inventory, $slot);
        return $instance;
    }

    private function sendContents(Inventory $inventory) : void{
        $items = [];

        foreach($inventory->getContents(true) as $slot => $item){
            $items[] = $this->toInstance($inventory, $slot, $item);
        }

        $this->session->sendDataPacket(InventoryContentPacket::create($this->windows[spl_object_id($inventory)], $items));
    }

    /**
     * Other players see the held item and the armor, so changes to those are shown to them too.
     */
    private function onVisibleChange(Inventory $inventory, ?int $slot) : void{
        if($inventory === $this->player->getArmorInventory()){
            $this->player->broadcastArmor();
        }elseif($inventory === $this->player->getOffHandInventory()){
            $this->player->broadcastOffHandItem();
        }elseif($inventory === $this->player->getInventory() && ($slot === null || $slot === $this->player->getInventory()->getHeldSlot())){
            $this->player->broadcastHeldItem();
        }
    }
}