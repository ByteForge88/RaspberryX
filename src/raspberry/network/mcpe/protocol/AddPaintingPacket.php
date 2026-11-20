<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

class AddPaintingPacket implements Packet{

    public int $actorRuntimeId = 0;

    public float $x = 0.0;
    public float $y = 0.0;
    public float $z = 0.0;

    //south 0, west 1, north 2, east 3
    public int $direction = 0;

    public string $motif = "";

    public static function create(int $actorRuntimeId, float $x, float $y, float $z, int $direction, string $motif) : self{
        $packet = new self();
        $packet->actorRuntimeId = $actorRuntimeId;
        $packet->x = $x;
        $packet->y = $y;
        $packet->z = $z;
        $packet->direction = $direction;
        $packet->motif = $motif;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::ADD_PAINTING_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeVarLong($this->actorRuntimeId);
        $writer->writeUnsignedVarLong($this->actorRuntimeId);
        $writer->writeFloat($this->x);
        $writer->writeFloat($this->y);
        $writer->writeFloat($this->z);
        $writer->writeVarInt($this->direction);
        $writer->writeString($this->motif);
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("AddPaintingPacket is server-to-client only");
    }
}