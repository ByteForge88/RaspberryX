<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class PlayerStartItemCooldownPacket implements Packet{

    public string $itemCategory = "";

    public int $cooldownTicks = 0;

    public static function create(string $itemCategory, int $cooldownTicks) : self{
        $packet = new self();
        $packet->itemCategory = $itemCategory;
        $packet->cooldownTicks = $cooldownTicks;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::PLAYER_START_ITEM_COOLDOWN_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeString($this->itemCategory);
        $writer->writeVarInt($this->cooldownTicks);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->itemCategory = $reader->readString();
        $this->cooldownTicks = $reader->readVarInt();
    }
}