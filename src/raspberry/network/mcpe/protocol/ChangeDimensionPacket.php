<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class ChangeDimensionPacket implements Packet{

    public int $dimension = 0;

    /** eye position */
    public float $x = 0.0;
    public float $y = 0.0;
    public float $z = 0.0;

    public bool $respawn = false;

    public ?int $loadingScreenId = null;

    public static function create(int $dimension, float $x, float $y, float $z, bool $respawn, ?int $loadingScreenId) : self{
        $packet = new self();
        $packet->dimension = $dimension;
        $packet->x = $x;
        $packet->y = $y;
        $packet->z = $z;
        $packet->respawn = $respawn;
        $packet->loadingScreenId = $loadingScreenId;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::CHANGE_DIMENSION_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeVarInt($this->dimension);
        $writer->writeFloat($this->x);
        $writer->writeFloat($this->y);
        $writer->writeFloat($this->z);
        $writer->writeBool($this->respawn);
        $writer->writeBool($this->loadingScreenId !== null);

        if($this->loadingScreenId !== null){
            $writer->writeUnsignedInt($this->loadingScreenId);
        }
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->dimension = $reader->readVarInt();
        $this->x = $reader->readFloat();
        $this->y = $reader->readFloat();
        $this->z = $reader->readFloat();
        $this->respawn = $reader->readBool();
        $this->loadingScreenId = $reader->readBool() ? $reader->readUnsignedInt() : null;
    }
}