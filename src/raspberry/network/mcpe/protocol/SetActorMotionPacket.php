<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

class SetActorMotionPacket implements Packet{

    public int $actorRuntimeId = 0;

    public float $x = 0.0;
    public float $y = 0.0;
    public float $z = 0.0;

    /** the last input tick of the player it is for, 0 for other entities */
    public int $tick = 0;

    public static function create(int $actorRuntimeId, float $x, float $y, float $z, int $tick) : self{
        $packet = new self();
        $packet->actorRuntimeId = $actorRuntimeId;
        $packet->x = $x;
        $packet->y = $y;
        $packet->z = $z;
        $packet->tick = $tick;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::SET_ACTOR_MOTION_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeUnsignedVarLong($this->actorRuntimeId);
        $writer->writeFloat($this->x);
        $writer->writeFloat($this->y);
        $writer->writeFloat($this->z);
        $writer->writeUnsignedVarLong($this->tick);
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("SetActorMotionPacket is server-to-client only");
    }
}