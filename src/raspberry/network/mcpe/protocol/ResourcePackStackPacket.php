<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

class ResourcePackStackPacket implements Packet{

    //TODO: custom resource packs
    public string $baseGameVersion = "*";

    public static function getPacketId() : int{
        return ProtocolPacketIds::RESOURCE_PACK_STACK_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeBool(false); //Packs required

        //empty resource-pack stack
        $writer->writeUnsignedVarInt(0);

        $writer->writeString($this->baseGameVersion, 64);

        $writer->writeUnsignedInt(0);
        $writer->writeBool(false); //Experiments previously toggled

        $writer->writeBool(false); //Include editor packs
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException(
            "ResourcePackStackPacket is server-to-client only"
        );
    }
}