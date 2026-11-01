<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use raspberry\network\mcpe\protocol\types\EntityMetadata;

use UnexpectedValueException;

class SetActorDataPacket implements Packet{

    public int $actorRuntimeId = 0;

    public EntityMetadata $metadata;

    public int $tick = 0;

    public static function create(int $actorRuntimeId, EntityMetadata $metadata, int $tick) : self{
        $packet = new self();
        $packet->actorRuntimeId = $actorRuntimeId;
        $packet->metadata = $metadata;
        $packet->tick = $tick;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::SET_ACTOR_DATA_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeUnsignedVarLong($this->actorRuntimeId);
        $this->metadata->write($writer);
        $writer->writeUnsignedVarInt(0); //entity properties: ints
        $writer->writeUnsignedVarInt(0); //entity properties: floats
        $writer->writeUnsignedVarLong($this->tick);
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("SetActorDataPacket is server-to-client only");
    }
}