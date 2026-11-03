<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use raspberry\network\mcpe\protocol\types\ItemInstance;
use raspberry\network\mcpe\serializer\ItemStackSerializer;

class MobEquipmentPacket implements Packet{

    public int $actorRuntimeId = 0;

    public ItemInstance $item;

    public int $inventorySlot = 0;

    public int $hotbarSlot = 0;

    public int $windowId = InventoryContentPacket::WINDOW_INVENTORY;

    public function __construct(){
        $this->item = ItemInstance::empty();
    }

    public static function create(int $actorRuntimeId, ItemInstance $item, int $slot, int $windowId) : self{
        $packet = new self();
        $packet->actorRuntimeId = $actorRuntimeId;
        $packet->item = $item;
        $packet->inventorySlot = $slot;
        $packet->hotbarSlot = $slot;
        $packet->windowId = $windowId;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::MOB_EQUIPMENT_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeUnsignedVarLong($this->actorRuntimeId);
        ItemStackSerializer::writeInstance($writer, $this->item->stack, $this->item->stackNetworkId);
        $writer->writeByte($this->inventorySlot);
        $writer->writeByte($this->hotbarSlot);
        $writer->writeByte($this->windowId);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->actorRuntimeId = $reader->readUnsignedVarLong();
        [$stack, $stackNetworkId] = ItemStackSerializer::readInstance($reader);
        $this->item = new ItemInstance($stackNetworkId, $stack);
        $this->inventorySlot = $reader->readByte();
        $this->hotbarSlot = $reader->readByte();
        $this->windowId = $reader->readByte();
    }
}