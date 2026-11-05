<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

class BlockEventPacket implements Packet{

    /** data: 1 to open the lid, 0 to close it */
    public const TYPE_CHANGE_CHEST_STATE = 1;

    public int $x = 0;
    public int $y = 0;
    public int $z = 0;

    public int $eventType = self::TYPE_CHANGE_CHEST_STATE;

    public int $eventData = 0;

    public static function create(int $x, int $y, int $z, int $eventType, int $eventData) : self{
        $packet = new self();
        $packet->x = $x;
        $packet->y = $y;
        $packet->z = $z;
        $packet->eventType = $eventType;
        $packet->eventData = $eventData;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::BLOCK_EVENT_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeVarInt($this->x);
        $writer->writeVarInt($this->y);
        $writer->writeVarInt($this->z);
        $writer->writeVarInt($this->eventType);
        $writer->writeVarInt($this->eventData);
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("BlockEventPacket is only sent by the server");
    }
}