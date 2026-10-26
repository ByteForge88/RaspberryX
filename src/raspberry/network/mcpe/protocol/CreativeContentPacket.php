<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

class CreativeContentPacket implements Packet{

    public static function getPacketId() : int{
        return ProtocolPacketIds::CREATIVE_CONTENT_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeUnsignedVarInt(0);
        $writer->writeUnsignedVarInt(0);
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("CreativeContentPacket is server-to-client only");
    }
}