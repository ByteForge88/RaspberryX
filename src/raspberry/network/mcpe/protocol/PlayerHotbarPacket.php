<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class PlayerHotbarPacket implements Packet{

    public int $selectedSlot = 0;

    public int $windowId = InventoryContentPacket::WINDOW_INVENTORY;

    public bool $selectSlot = true;

    public static function create(int $selectedSlot) : self{
        $packet = new self();
        $packet->selectedSlot = $selectedSlot;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::PLAYER_HOTBAR_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeUnsignedVarInt($this->selectedSlot);
        $writer->writeByte($this->windowId);
        $writer->writeBool($this->selectSlot);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->selectedSlot = $reader->readUnsignedVarInt();
        $this->windowId = $reader->readByte();
        $this->selectSlot = $reader->readBool();
    }
}