<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class PlayerToggleCrafterSlotRequestPacket implements Packet{

    public int $x = 0;
    public int $y = 0;
    public int $z = 0;

    public int $slot = 0;

    public bool $disabled = false;

    public static function getPacketId() : int{
        return ProtocolPacketIds::PLAYER_TOGGLE_CRAFTER_SLOT_REQUEST_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeInt($this->x);
        $writer->writeInt($this->y);
        $writer->writeInt($this->z);
        $writer->writeByte($this->slot);
        $writer->writeBool($this->disabled);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->x = $reader->readInt();
        $this->y = $reader->readInt();
        $this->z = $reader->readInt();
        $this->slot = $reader->readByte();
        $this->disabled = $reader->readBool();
    }
}