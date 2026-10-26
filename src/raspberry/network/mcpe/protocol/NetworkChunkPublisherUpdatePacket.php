<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class NetworkChunkPublisherUpdatePacket implements Packet{

    public int $x = 0;
    public int $y = 0;
    public int $z = 0;

    /** radius in blocks, not chunks */
    public int $radius = 0;

    public static function create(int $x, int $y, int $z, int $radiusBlocks) : self{
        $packet = new self();
        $packet->x = $x;
        $packet->y = $y;
        $packet->z = $z;
        $packet->radius = $radiusBlocks;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::NETWORK_CHUNK_PUBLISHER_UPDATE_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeVarInt($this->x);
        $writer->writeVarInt($this->y);
        $writer->writeVarInt($this->z);
        $writer->writeUnsignedVarInt($this->radius);
        $writer->writeUnsignedInt(0);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->x = $reader->readVarInt();
        $this->y = $reader->readVarInt();
        $this->z = $reader->readVarInt();
        $this->radius = $reader->readUnsignedVarInt();

        $saved = $reader->readUnsignedInt();

        for($i = 0; $i < $saved; ++$i){
            $reader->readVarInt();
            $reader->readVarInt();
        }
    }
}