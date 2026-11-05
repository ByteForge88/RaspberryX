<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

class OpenSignPacket implements Packet{

    public int $x = 0;
    public int $y = 0;
    public int $z = 0;

    public bool $front = true;

    public static function create(int $x, int $y, int $z, bool $front) : self{
        $packet = new self();
        $packet->x = $x;
        $packet->y = $y;
        $packet->z = $z;
        $packet->front = $front;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::OPEN_SIGN_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeVarInt($this->x);
        $writer->writeVarInt($this->y);
        $writer->writeVarInt($this->z);
        $writer->writeBool($this->front);
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("OpenSignPacket is only sent by the server");
    }
}