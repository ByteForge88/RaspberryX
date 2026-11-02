<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class RespawnPacket implements Packet{

    public const STATE_SEARCHING_FOR_SPAWN = 0;
    public const STATE_READY_TO_SPAWN = 1;
    public const STATE_CLIENT_READY_TO_SPAWN = 2;

    /** eye position */
    public float $x = 0.0;
    public float $y = 0.0;
    public float $z = 0.0;

    public int $state = self::STATE_SEARCHING_FOR_SPAWN;

    public int $actorRuntimeId = 0;

    public static function create(float $x, float $y, float $z, int $state, int $actorRuntimeId) : self{
        $packet = new self();
        $packet->x = $x;
        $packet->y = $y;
        $packet->z = $z;
        $packet->state = $state;
        $packet->actorRuntimeId = $actorRuntimeId;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::RESPAWN_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeFloat($this->x);
        $writer->writeFloat($this->y);
        $writer->writeFloat($this->z);
        $writer->writeByte($this->state);
        $writer->writeUnsignedVarLong($this->actorRuntimeId);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->x = $reader->readFloat();
        $this->y = $reader->readFloat();
        $this->z = $reader->readFloat();
        $this->state = $reader->readByte();
        $this->actorRuntimeId = $reader->readUnsignedVarLong();
    }
}