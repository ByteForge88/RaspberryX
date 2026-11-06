<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class LecternUpdatePacket implements Packet{

    public int $page = 0;
    public int $totalPages = 0;

    public int $x = 0;
    public int $y = 0;
    public int $z = 0;

    public static function getPacketId() : int{
        return ProtocolPacketIds::LECTERN_UPDATE_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeByte($this->page);
        $writer->writeByte($this->totalPages);
        $writer->writeVarInt($this->x);
        $writer->writeVarInt($this->y);
        $writer->writeVarInt($this->z);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->page = $reader->readByte();
        $this->totalPages = $reader->readByte();
        $this->x = $reader->readVarInt();
        $this->y = $reader->readVarInt();
        $this->z = $reader->readVarInt();
    }
}