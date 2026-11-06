<?php

declare(strict_types=1);

namespace raspberry\network\mcpe;

use raspberry\block\entity\BlockEntity;

use raspberry\inventory\Inventory;
use raspberry\inventory\InventoryListener;

use raspberry\item\Item;

use raspberry\network\mcpe\protocol\ContainerClosePacket;
use raspberry\network\mcpe\protocol\ContainerOpenPacket;
use raspberry\network\mcpe\protocol\InventoryContentPacket;
use raspberry\network\mcpe\protocol\InventorySlotPacket;
use raspberry\network\mcpe\protocol\PlayerHotbarPacket;
use raspberry\network\mcpe\protocol\types\ItemInstance;

use raspberry\player\Player;

use Closure;

use function array_unique;
use function array_values;
use function ksort;
use function spl_object_id;

class InventoryManager implements InventoryListener{

    private const MAX_WINDOW_ID = 99;

    private NetworkSession $session;

    private Player $player;

    /** @var array<int, Inventory> */
    private array $inventories = [];

    /** @var array<int, int> inventory => window ID */
    private array $windows = [];

    /** @var array<int, int> */
    private array $offsets = [];

    private ?ContainerWindow $window = null;

    private int $lastWindowId = 0;

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
        foreach(array_unique($this->windows) as $windowId){
            $this->sendWindow($windowId);
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
            $this->session->sendDataPacket(InventorySlotPacket::create($this->windows[$key], $slot + $this->offsets[$key], $this->toInstance($inventory, $slot, $inventory->getItem($slot))));
        }
    }

    public function getWindow() : ?ContainerWindow{
        return $this->window;
    }

    /**
     * Opens a container screen showing $inventories (none for a crafting table), closing the
     * one that was open.
     *
     * @param list<array{int, int, int}> $positions the blocks it belongs to, the first is the one opened
     * @param list<int>                  $slotContainerIds the container IDs of its slots in inventory requests
     * @param list<Inventory>            $inventories
     * @param (Closure() : void)|null    $onClose
     */
    public function openWindow(int $type, array $positions, array $slotContainerIds, array $inventories, ?Closure $onClose = null, ?BlockEntity $entity = null) : ContainerWindow{
        $this->closeWindow(true);

        $this->lastWindowId = $this->lastWindowId % self::MAX_WINDOW_ID + 1;
        $window = new ContainerWindow($this->lastWindowId, $type, $positions, $slotContainerIds, $inventories, $onClose, $entity);
        $this->window = $window;

        [$x, $y, $z] = $positions[0];
        $this->session->sendDataPacket(ContainerOpenPacket::create($window->getWindowId(), $type, $x, $y, $z, -1));

        foreach($window->getParts() as [$inventory, $offset]){
            $this->addWindow($inventory, $window->getWindowId(), $offset, false);
        }

        if($window->getParts() !== []){
            $this->sendWindow($window->getWindowId());
        }

        return $window;
    }

    /**
     * Opens a screen the server keeps nothing for, like a lectern's book. The client does not
     * say when it closes.
     */
    public function openScreen(int $type, int $x, int $y, int $z) : void{
        $this->closeWindow(true);

        $this->lastWindowId = $this->lastWindowId % self::MAX_WINDOW_ID + 1;
        $this->session->sendDataPacket(ContainerOpenPacket::create($this->lastWindowId, $type, $x, $y, $z, -1));
    }

    public function closeWindow(bool $tellClient) : void{
        $window = $this->window;

        if($window === null){
            return;
        }

        $this->window = null;

        foreach($window->getParts() as [$inventory]){
            $key = spl_object_id($inventory);

            unset($this->windows[$key], $this->offsets[$key], $this->inventories[$key], $this->stackIds[$key]);
            $inventory->removeListener($this);
        }

        if($tellClient){
            $this->session->sendDataPacket(ContainerClosePacket::create($window->getWindowId(), $window->getType(), true));
        }

        $window->onClose();
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

        $this->session->sendDataPacket(InventorySlotPacket::create($this->windows[$key], $slot + $this->offsets[$key], $this->toInstance($inventory, $slot, $item)));
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

        $this->sendWindow($this->windows[$key]);
        $this->onVisibleChange($inventory, null);
    }

    public function onRequestChange(Inventory $inventory, int $slot) : void{
        $this->onVisibleChange($inventory, $slot);
    }

    private function addWindow(Inventory $inventory, int $windowId, int $offset = 0, bool $send = true) : void{
        $key = spl_object_id($inventory);
        $this->inventories[$key] = $inventory;
        $this->windows[$key] = $windowId;
        $this->offsets[$key] = $offset;
        $inventory->addListener($this);

        if($send){
            $this->onContentChange($inventory);
            return;
        }

        $this->stackIds[$key] = [];

        foreach($inventory->getContents() as $slot => $item){
            $this->stackIds[$key][$slot] = $this->nextStackId++;
        }
    }

    private function toInstance(Inventory $inventory, int $slot, Item $item) : ItemInstance{
        $instance = $this->session->toItemInstance($item);
        $instance->stackNetworkId = $instance->stack->isEmpty() ? 0 : $this->getStackId($inventory, $slot);
        return $instance;
    }

    /**
     * Sends every slot of a window: all inventories shown in it, one after the other.
     */
    private function sendWindow(int $windowId) : void{
        $items = [];

        foreach($this->inventories as $key => $inventory){
            if($this->windows[$key] !== $windowId){
                continue;
            }

            foreach($inventory->getContents(true) as $slot => $item){
                $items[$this->offsets[$key] + $slot] = $this->toInstance($inventory, $slot, $item);
            }
        }

        ksort($items);
        $this->session->sendDataPacket(InventoryContentPacket::create($windowId, array_values($items)));
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