<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class RemoveActorPacket implements Packet{

    public int $actorUniqueId = 0;

    public static function create(int $actorUniqueId) : self{
        $packet = new self();
        $packet->actorUniqueId = $actorUniqueId;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::REMOVE_ACTOR_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeVarLong($this->actorUniqueId);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->actorUniqueId = $reader->readVarLong();
    }
}