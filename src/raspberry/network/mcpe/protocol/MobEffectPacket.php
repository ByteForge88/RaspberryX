<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

class MobEffectPacket implements Packet{

    public const EVENT_ADD = 1;
    public const EVENT_UPDATE = 2;
    public const EVENT_REMOVE = 3;

    public int $actorRuntimeId = 0;

    public int $event = self::EVENT_ADD;

    public int $effectId = 0;

    public int $amplifier = 0;

    public bool $particles = true;

    public int $duration = 0;

    public int $tick = 0;

    public bool $ambient = false;

    public static function create(int $actorRuntimeId, int $event, int $effectId, int $amplifier, bool $particles, int $duration, bool $ambient, int $tick) : self{
        $packet = new self();
        $packet->actorRuntimeId = $actorRuntimeId;
        $packet->event = $event;
        $packet->effectId = $effectId;
        $packet->amplifier = $amplifier;
        $packet->particles = $particles;
        $packet->duration = $duration;
        $packet->ambient = $ambient;
        $packet->tick = $tick;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::MOB_EFFECT_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeUnsignedVarLong($this->actorRuntimeId);
        $writer->writeByte($this->event);
        $writer->writeVarInt($this->effectId);
        $writer->writeVarInt($this->amplifier);
        $writer->writeBool($this->particles);
        $writer->writeVarInt($this->duration);
        $writer->writeUnsignedVarLong($this->tick);
        $writer->writeBool($this->ambient);
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("MobEffectPacket is server-to-client only");
    }
}