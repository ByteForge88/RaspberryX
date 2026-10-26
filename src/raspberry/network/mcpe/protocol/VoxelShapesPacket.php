<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

class VoxelShapesPacket implements Packet{

    public static function getPacketId() : int{
        return ProtocolPacketIds::VOXEL_SHAPES_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeUnsignedVarInt(0);
        $writer->writeUnsignedVarInt(0);
        $writer->writeUnsignedShort(0);
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("VoxelShapesPacket is server-to-client only");
    }
}