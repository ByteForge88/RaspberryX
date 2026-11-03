<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use raspberry\network\mcpe\protocol\types\EntityMetadata;
use raspberry\network\mcpe\protocol\types\ItemInstance;
use raspberry\network\mcpe\serializer\ItemStackSerializer;

use UnexpectedValueException;

class AddItemActorPacket implements Packet{

    public int $actorRuntimeId = 0;

    public ItemInstance $item;

    public float $x = 0.0;
    public float $y = 0.0;
    public float $z = 0.0;

    public float $motionX = 0.0;
    public float $motionY = 0.0;
    public float $motionZ = 0.0;

    public EntityMetadata $metadata;

    public bool $fromFishing = false;

    public function __construct(){
        $this->item = ItemInstance::empty();
        $this->metadata = new EntityMetadata();
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::ADD_ITEM_ACTOR_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeVarLong($this->actorRuntimeId);
        $writer->writeUnsignedVarLong($this->actorRuntimeId);
        ItemStackSerializer::writeInstance($writer, $this->item->stack, $this->item->stackNetworkId);
        $writer->writeFloat($this->x);
        $writer->writeFloat($this->y);
        $writer->writeFloat($this->z);
        $writer->writeFloat($this->motionX);
        $writer->writeFloat($this->motionY);
        $writer->writeFloat($this->motionZ);
        $this->metadata->write($writer);
        $writer->writeBool($this->fromFishing);
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("AddItemActorPacket is server-to-client only");
    }
}