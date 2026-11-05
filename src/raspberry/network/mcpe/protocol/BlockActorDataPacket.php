<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\nbt\NbtSerializer;
use raspberry\nbt\tag\CompoundTag;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class BlockActorDataPacket implements Packet{

    public int $x = 0;
    public int $y = 0;
    public int $z = 0;

    public CompoundTag $nbt;

    public function __construct(){
        $this->nbt = new CompoundTag();
    }

    public static function create(int $x, int $y, int $z, CompoundTag $nbt) : self{
        $packet = new self();
        $packet->x = $x;
        $packet->y = $y;
        $packet->z = $z;
        $packet->nbt = $nbt;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::BLOCK_ACTOR_DATA_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeVarInt($this->x);
        $writer->writeVarInt($this->y);
        $writer->writeVarInt($this->z);
        $writer->writeBytes(NbtSerializer::network()->write($this->nbt));
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->x = $reader->readVarInt();
        $this->y = $reader->readVarInt();
        $this->z = $reader->readVarInt();
        $this->nbt = NbtSerializer::network()->readFrom($reader);
    }
}