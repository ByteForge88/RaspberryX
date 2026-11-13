<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

class SetTimePacket implements Packet{

    public int $time = 0;

    public static function create(int $time) : self{
        $packet = new self();
        //the field is an int32, the moon only needs the day count to stay right modulo 8
        $packet->time = $time & 0x7fffffff;

        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::SET_TIME_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeVarInt($this->time);
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("SetTimePacket is server-to-client only");
    }
}