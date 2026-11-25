<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

class PlayerActionPacket implements Packet{

    public const int ACTION_START_SLEEPING = 5;
    public const int ACTION_STOP_SLEEPING = 6;
    public const int ACTION_RESPAWN = 7;
    public const int ACTION_DIMENSION_CHANGE_DONE = 14;

    public int $actorRuntimeId = 0;

    public int $action = 0;

    public int $x = 0;
    public int $y = 0;
    public int $z = 0;

    public int $resultX = 0;
    public int $resultY = 0;
    public int $resultZ = 0;

    public int $face = 0;

    public static function create(int $actorRuntimeId, int $action) : self{
        $packet = new self();
        $packet->actorRuntimeId = $actorRuntimeId;
        $packet->action = $action;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::PLAYER_ACTION_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeUnsignedVarLong($this->actorRuntimeId);
        $writer->writeVarInt($this->action);
        $writer->writeVarInt($this->x);
        $writer->writeVarInt($this->y);
        $writer->writeVarInt($this->z);
        $writer->writeVarInt($this->resultX);
        $writer->writeVarInt($this->resultY);
        $writer->writeVarInt($this->resultZ);
        $writer->writeVarInt($this->face);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->actorRuntimeId = $reader->readUnsignedVarLong();
        $this->action = $reader->readVarInt();
        $this->x = $reader->readVarInt();
        $this->y = $reader->readVarInt();
        $this->z = $reader->readVarInt();
        $this->resultX = $reader->readVarInt();
        $this->resultY = $reader->readVarInt();
        $this->resultZ = $reader->readVarInt();
        $this->face = $reader->readVarInt();
    }
}