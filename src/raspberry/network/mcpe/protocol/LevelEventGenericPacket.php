<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

class LevelEventGenericPacket implements Packet{

    public int $event = 0;

    public string $data = "";

    public static function create(int $event, string $data) : self{
        $packet = new self();
        $packet->event = $event;
        $packet->data = $data;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::LEVEL_EVENT_GENERIC_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeVarInt($this->event);
        $writer->writeBytes($this->data);
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("LevelEventGenericPacket is only sent by the server");
    }
}