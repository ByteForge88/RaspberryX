<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class StopSoundPacket implements Packet{

    public string $soundName = "";

    public bool $stopAll = false;

    public bool $stopLegacyMusic = false;

    public static function create(string $soundName) : self{
        $packet = new self();
        $packet->soundName = $soundName;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::STOP_SOUND_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeString($this->soundName);
        $writer->writeBool($this->stopAll);
        $writer->writeBool($this->stopLegacyMusic);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->soundName = $reader->readString();
        $this->stopAll = $reader->readBool();
        $this->stopLegacyMusic = $reader->readBool();
    }
}