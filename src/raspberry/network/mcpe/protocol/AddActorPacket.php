<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;
use raspberry\network\mcpe\protocol\types\EntityMetadata;

use UnexpectedValueException;

class AddActorPacket implements Packet{

    public int $actorRuntimeId = 0;

    public string $type = "";

    public float $x = 0.0;
    public float $y = 0.0;
    public float $z = 0.0;

    public float $motionX = 0.0;
    public float $motionY = 0.0;
    public float $motionZ = 0.0;

    public float $pitch = 0.0;
    public float $yaw = 0.0;

    public EntityMetadata $metadata;

    public function __construct(){
        $this->metadata = new EntityMetadata();
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::ADD_ACTOR_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeVarLong($this->actorRuntimeId);
        $writer->writeUnsignedVarLong($this->actorRuntimeId);
        $writer->writeString($this->type);
        $writer->writeFloat($this->x);
        $writer->writeFloat($this->y);
        $writer->writeFloat($this->z);
        $writer->writeFloat($this->motionX);
        $writer->writeFloat($this->motionY);
        $writer->writeFloat($this->motionZ);
        $writer->writeFloat($this->pitch);
        $writer->writeFloat($this->yaw);
        $writer->writeFloat($this->yaw); //head
        $writer->writeFloat($this->yaw); //body
        $writer->writeUnsignedVarInt(0); //attributes
        $this->metadata->write($writer);
        $writer->writeUnsignedVarInt(0); //int properties
        $writer->writeUnsignedVarInt(0); //float properties
        $writer->writeUnsignedVarInt(0); //links
        $writer->writeBool(false); //not sitting on a block
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("AddActorPacket is server-to-client only");
    }
}