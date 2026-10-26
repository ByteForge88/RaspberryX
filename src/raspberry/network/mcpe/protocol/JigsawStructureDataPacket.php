<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;
use raspberry\network\mcpe\serializer\NetworkNbtWriter;

use UnexpectedValueException;

class JigsawStructureDataPacket implements Packet{

    public static function getPacketId() : int{
        return ProtocolPacketIds::JIGSAW_STRUCTURE_DATA_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $nbt = new NetworkNbtWriter();
        $nbt->startCompound();

        foreach(["processors", "template_pools", "jigsaws", "structure_sets"] as $list){
            $nbt->writeEmptyList($list, NetworkNbtWriter::TAG_COMPOUND);
        }

        $nbt->endCompound();
        $writer->writeBytes($nbt->getBuffer());
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("JigsawStructureDataPacket is server-to-client only");
    }
}