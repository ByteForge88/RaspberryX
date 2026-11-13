<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\nbt\NbtSerializer;
use raspberry\nbt\tag\CompoundTag;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

class SyncActorPropertyPacket implements Packet{

    public CompoundTag $properties;

    public static function create(CompoundTag $properties) : self{
        $packet = new self();
        $packet->properties = $properties;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::SYNC_ACTOR_PROPERTY_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeBytes(NbtSerializer::network()->write($this->properties));
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("SyncActorPropertyPacket is server-to-client only");
    }
}