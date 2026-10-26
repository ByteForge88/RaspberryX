<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class RequestChunkRadiusPacket implements Packet{

    public int $radius = 0;

    /** the highest radius the client can handle */
    public int $maxRadius = 0;

    public static function getPacketId() : int{
        return ProtocolPacketIds::REQUEST_CHUNK_RADIUS_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeVarInt($this->radius);
        $writer->writeByte($this->maxRadius);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->radius = $reader->readVarInt();
        $this->maxRadius = $reader->readByte();
    }
}