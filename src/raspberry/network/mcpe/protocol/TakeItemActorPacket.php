<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class TakeItemActorPacket implements Packet{

    public int $itemActorRuntimeId = 0;

    public int $takerActorRuntimeId = 0;

    public static function create(int $itemActorRuntimeId, int $takerActorRuntimeId) : self{
        $packet = new self();
        $packet->itemActorRuntimeId = $itemActorRuntimeId;
        $packet->takerActorRuntimeId = $takerActorRuntimeId;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::TAKE_ITEM_ACTOR_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeUnsignedVarLong($this->itemActorRuntimeId);
        $writer->writeUnsignedVarLong($this->takerActorRuntimeId);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->itemActorRuntimeId = $reader->readUnsignedVarLong();
        $this->takerActorRuntimeId = $reader->readUnsignedVarLong();
    }
}