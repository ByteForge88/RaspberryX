<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

class UpdateBlockPacket implements Packet{

    public const FLAG_NEIGHBORS = 1;
    public const FLAG_NETWORK = 2;

    public int $x = 0;
    public int $y = 0;
    public int $z = 0;

    public int $blockNetworkId = 0;

    public int $flags = self::FLAG_NETWORK;

    /** 0: blocks, 1: liquids inside blocks */
    public int $layer = 0;

    public static function create(int $x, int $y, int $z, int $blockNetworkId) : self{
        $packet = new self();
        $packet->x = $x;
        $packet->y = $y;
        $packet->z = $z;
        $packet->blockNetworkId = $blockNetworkId;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::UPDATE_BLOCK_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeVarInt($this->x);
        $writer->writeVarInt($this->y);
        $writer->writeVarInt($this->z);
        $writer->writeUnsignedVarInt($this->blockNetworkId & 0xffffffff);
        $writer->writeUnsignedVarInt($this->flags);
        $writer->writeUnsignedVarInt($this->layer);
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("UpdateBlockPacket is server-to-client only");
    }
}