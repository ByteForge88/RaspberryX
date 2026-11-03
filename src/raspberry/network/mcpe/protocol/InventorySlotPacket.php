<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use raspberry\network\mcpe\protocol\types\ItemInstance;
use raspberry\network\mcpe\serializer\ItemStackSerializer;

use UnexpectedValueException;

class InventorySlotPacket implements Packet{

    public int $windowId = InventoryContentPacket::WINDOW_INVENTORY;

    public int $slot = 0;

    public ItemInstance $item;

    public function __construct(){
        $this->item = ItemInstance::empty();
    }

    public static function create(int $windowId, int $slot, ItemInstance $item) : self{
        $packet = new self();
        $packet->windowId = $windowId;
        $packet->slot = $slot;
        $packet->item = $item;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::INVENTORY_SLOT_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeUnsignedVarInt($this->windowId);
        $writer->writeUnsignedVarInt($this->slot);
        $writer->writeBool(false); //no container name
        $writer->writeBool(false); //no storage item
        ItemStackSerializer::writeInstance($writer, $this->item->stack, $this->item->stackNetworkId);
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("InventorySlotPacket is server-to-client only");
    }
}