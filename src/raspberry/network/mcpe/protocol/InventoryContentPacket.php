<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use raspberry\network\mcpe\protocol\types\ItemInstance;
use raspberry\network\mcpe\serializer\ItemStackSerializer;

use UnexpectedValueException;

use function count;

class InventoryContentPacket implements Packet{

    public const WINDOW_INVENTORY = 0;
    public const WINDOW_OFFHAND = 119;
    public const WINDOW_ARMOR = 120;
    public const WINDOW_UI = 124;

    public int $windowId = self::WINDOW_INVENTORY;

    /** @var list<ItemInstance> */
    public array $items = [];

    /**
     * @param list<ItemInstance> $items
     */
    public static function create(int $windowId, array $items) : self{
        $packet = new self();
        $packet->windowId = $windowId;
        $packet->items = $items;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::INVENTORY_CONTENT_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeUnsignedVarInt($this->windowId);
        $writer->writeUnsignedVarInt(count($this->items));

        foreach($this->items as $item){
            ItemStackSerializer::writeInstance($writer, $item->stack, $item->stackNetworkId);
        }

        $writer->writeByte(0); //container name: none
        $writer->writeBool(false); //no dynamic container ID
        ItemStackSerializer::writeInstance($writer, ItemInstance::empty()->stack, 0); //storage item (bundles): none
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("InventoryContentPacket is server-to-client only");
    }
}