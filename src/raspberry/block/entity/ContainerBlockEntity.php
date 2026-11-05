<?php

declare(strict_types=1);

namespace raspberry\block\entity;

use raspberry\inventory\Inventory;
use raspberry\inventory\InventoryListener;

use raspberry\item\Item;
use raspberry\item\ItemSerializer;

use raspberry\nbt\NbtException;
use raspberry\nbt\tag\CompoundTag;
use raspberry\nbt\tag\ListTag;
use raspberry\nbt\tag\Tag;

use raspberry\network\mcpe\protocol\ContainerSetDataPacket;

use raspberry\player\Player;

use raspberry\world\World;

use function array_values;

abstract class ContainerBlockEntity extends BlockEntity implements InventoryListener{

    public const SIZE = 27;

    private Inventory $inventory;

    private ?string $customName = null;

    public function __construct(int $x, int $y, int $z, int $size = self::SIZE){
        parent::__construct($x, $y, $z);

        $this->inventory = new Inventory($size);
        $this->inventory->addListener($this);
    }

    public function getInventory() : Inventory{
        return $this->inventory;
    }

    public function getCustomName() : ?string{
        return $this->customName;
    }

    public function readData(CompoundTag $nbt) : void{
        $this->inventory->setContents(self::readItems($nbt, $this->inventory->getSize()));
        $this->customName = $nbt->getString("CustomName");
    }

    public function writeData(CompoundTag $nbt) : void{
        $nbt->setTag("Items", self::writeItems($this->inventory->getContents()));

        if($this->customName !== null){
            $nbt->setString("CustomName", $this->customName);
        }
    }

    public function onPlace(Item $item, Player $player, int $face) : void{
        $this->customName = $item->getCustomName();
    }

    public function onBreak(World $world) : array{
        $contents = array_values($this->inventory->getContents());
        $this->inventory->clearAll();

        return $contents;
    }

    public function onSlotChange(Inventory $inventory, int $slot, Item $oldItem) : void{
        $this->setChanged();
    }

    public function onContentChange(Inventory $inventory) : void{
        $this->setChanged();
    }

    /**
     * Called when a player opens the screen, to send what the screen shows beside the items.
     */
    public function onOpen(Player $player) : void{
    }

    /**
     * @param array<int, int>   $values  key => value
     * @param list<Player>|null $players null for every viewer
     */
    protected function sendContainerData(array $values, ?array $players = null) : void{
        foreach($players ?? $this->getViewers() as $player){
            $window = $player->getNetworkSession()->getInventoryManager()?->getWindow();

            if($window === null || !$window->isAt($this->getX(), $this->getY(), $this->getZ())){
                continue;
            }

            foreach($values as $key => $value){
                $player->getNetworkSession()->sendDataPacket(ContainerSetDataPacket::create($window->getWindowId(), $key, $value));
            }
        }
    }

    /**
     * @return array<int, Item>
     */
    public static function readItems(CompoundTag $nbt, int $size = self::SIZE) : array{
        $items = [];

        foreach($nbt->getListTag("Items")?->getValue() ?? [] as $entry){
            if(!$entry instanceof CompoundTag){
                continue;
            }

            $slot = $entry->getByte("Slot", -1) ?? -1;

            if($slot < 0 || $slot >= $size){
                continue;
            }

            try{
                $items[$slot] = ItemSerializer::deserialize($entry);
            }catch(NbtException){
                continue;
            }
        }

        return $items;
    }

    /**
     * @param array<int, Item> $items
     */
    public static function writeItems(array $items) : ListTag{
        $list = new ListTag([], Tag::TAG_COMPOUND);

        foreach($items as $slot => $item){
            $list->push(ItemSerializer::serialize($item, $slot));
        }

        return $list;
    }
}