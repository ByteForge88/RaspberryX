<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class ChunkRadiusUpdatedPacket implements Packet{

    public int $radius = 0;

    public static function create(int $radius) : self{
        $packet = new self();
        $packet->radius = $radius;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::CHUNK_RADIUS_UPDATED_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeVarInt($this->radius);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->radius = $reader->readVarInt();
    }
}