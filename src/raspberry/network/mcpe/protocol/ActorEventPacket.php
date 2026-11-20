<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class ActorEventPacket implements Packet{

    public const int JUMP = 1;
    public const int HURT = 2;
    public const int DEATH = 3;
    public const int ARM_SWING = 4;
    public const int EAT_GRASS = 10;
    public const int RESPAWN = 18;
    public const int LOVE_PARTICLES = 21;
    public const int EATING_ITEM = 57;
    public const int BABY_ANIMAL_FEED = 60;
    public const int DEATH_SMOKE_CLOUD = 61;

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