<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

class LevelEventPacket implements Packet{

    public const PARTICLES_DESTROY_BLOCK = 2001;

    public const START_BLOCK_CRACKING = 3600;
    public const STOP_BLOCK_CRACKING = 3601;
    public const UPDATE_BLOCK_CRACKING = 3602;

    public int $event = 0;

    public float $x = 0.0;
    public float $y = 0.0;
    public float $z = 0.0;

    public int $data = 0;

    public static function create(int $event, float $x, float $y, float $z, int $data = 0) : self{
        $packet = new self();
        $packet->event = $event;
        $packet->x = $x;
        $packet->y = $y;
        $packet->z = $z;
        $packet->data = $data;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::LEVEL_EVENT_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeVarInt($this->event);
        $writer->writeFloat($this->x);
        $writer->writeFloat($this->y);
        $writer->writeFloat($this->z);
        $writer->writeVarInt($this->data);
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("LevelEventPacket is server-to-client only");
    }
}