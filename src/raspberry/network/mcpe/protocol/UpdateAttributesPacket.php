<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use raspberry\network\mcpe\protocol\types\Attribute;

use function count;

class UpdateAttributesPacket implements Packet{

    public int $actorRuntimeId = 0;

    /** @var list<Attribute> */
    public array $attributes = [];

    public int $tick = 0;

    /**
     * @param list<Attribute> $attributes
     */
    public static function create(int $actorRuntimeId, array $attributes, int $tick) : self{
        $packet = new self();
        $packet->actorRuntimeId = $actorRuntimeId;
        $packet->attributes = $attributes;
        $packet->tick = $tick;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::UPDATE_ATTRIBUTES_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeUnsignedVarLong($this->actorRuntimeId);
        $writer->writeUnsignedVarInt(count($this->attributes));

        foreach($this->attributes as $attribute){
            $attribute->write($writer);
        }

        $writer->writeUnsignedVarLong($this->tick);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->actorRuntimeId = $reader->readUnsignedVarLong();

        for($i = 0, $count = $reader->readUnsignedVarInt(); $i < $count; ++$i){
            $this->attributes[] = Attribute::read($reader);
        }

        $this->tick = $reader->readUnsignedVarLong();
    }
}