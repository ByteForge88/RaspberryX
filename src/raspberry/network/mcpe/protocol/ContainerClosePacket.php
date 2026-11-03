<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class ContainerClosePacket implements Packet{

    public int $windowId = 0;

    public int $type = 0;

    public bool $serverSide = false;

    public static function create(int $windowId, int $type, bool $serverSide) : self{
        $packet = new self();
        $packet->windowId = $windowId;
        $packet->type = $type;
        $packet->serverSide = $serverSide;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::CONTAINER_CLOSE_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeByte($this->windowId);
        $writer->writeByte($this->type & 0xff);
        $writer->writeBool($this->serverSide);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->windowId = $reader->readByte();
        $this->type = $reader->readByte();
        $this->serverSide = $reader->readBool();
    }
}