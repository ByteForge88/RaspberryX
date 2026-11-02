<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class ActorEventPacket implements Packet{

    public const JUMP = 1;
    public const HURT = 2;
    public const DEATH = 3;
    public const RESPAWN = 18;

    public int $actorRuntimeId = 0;

    public int $event = 0;

    public int $data = 0;

    public static function create(int $actorRuntimeId, int $event, int $data = 0) : self{
        $packet = new self();
        $packet->actorRuntimeId = $actorRuntimeId;
        $packet->event = $event;
        $packet->data = $data;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::ACTOR_EVENT_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeUnsignedVarLong($this->actorRuntimeId);
        $writer->writeByte($this->event);
        $writer->writeVarInt($this->data);
        $writer->writeBool(false);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->actorRuntimeId = $reader->readUnsignedVarLong();
        $this->event = $reader->readByte();
        $this->data = $reader->readVarInt();

        if($reader->readBool()){
            $reader->readFloat();
            $reader->readFloat();
            $reader->readFloat();
        }
    }
}