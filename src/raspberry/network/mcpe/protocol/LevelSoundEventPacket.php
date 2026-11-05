<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use raspberry\network\mcpe\protocol\types\LevelSoundEvent;

use UnexpectedValueException;

class LevelSoundEventPacket implements Packet{

    public string $sound = LevelSoundEvent::UNDEFINED;

    public float $x = 0.0;
    public float $y = 0.0;
    public float $z = 0.0;

    public int $extraData = -1;

    public string $entityType = "";

    public bool $babyMob = false;

    public bool $disableRelativeVolume = false;

    public int $entityUniqueId = -1;

    public static function create(string $sound, float $x, float $y, float $z, int $extraData = -1) : self{
        $packet = new self();
        $packet->sound = $sound;
        $packet->x = $x;
        $packet->y = $y;
        $packet->z = $z;
        $packet->extraData = $extraData;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::LEVEL_SOUND_EVENT_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeString($this->sound);
        $writer->writeFloat($this->x);
        $writer->writeFloat($this->y);
        $writer->writeFloat($this->z);
        $writer->writeVarInt($this->extraData);
        $writer->writeString($this->entityType);
        $writer->writeBool($this->babyMob);
        $writer->writeBool($this->disableRelativeVolume);
        $writer->writeLong($this->entityUniqueId);
        $writer->writeBool(false); //no separate position to play it at
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("LevelSoundEventPacket is server-to-client only");
    }
}