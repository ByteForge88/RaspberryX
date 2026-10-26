<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

use function str_repeat;

class ResourcePacksInfoPacket implements Packet{

    public static function getPacketId() : int{
        return ProtocolPacketIds::RESOURCE_PACKS_INFO_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeBool(false); //Packs required
        $writer->writeBool(false); //Has addons
        $writer->writeBool(false); //Has scripts
        $writer->writeBool(false); //Disable Vibrant Visuals

        $writer->writeBytes(str_repeat("\x00", 16));
        $writer->writeString("");

        $writer->writeUnsignedVarInt(0);
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("ResourcePacksInfoPacket is server-to-client only");
    }
}