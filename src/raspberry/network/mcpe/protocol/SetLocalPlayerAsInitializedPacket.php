<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class SetLocalPlayerAsInitializedPacket implements Packet{

    public int $actorRuntimeId = 0;

    public static function getPacketId() : int{
        return ProtocolPacketIds::SET_LOCAL_PLAYER_AS_INITIALIZED_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeUnsignedVarLong($this->actorRuntimeId);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->actorRuntimeId = $reader->readUnsignedVarLong();
    }
}