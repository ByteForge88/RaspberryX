<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use function round;

class MoveActorAbsolutePacket implements Packet{

    public const FLAG_ON_GROUND = 1;
    public const FLAG_TELEPORT = 2;

    public int $actorRuntimeId = 0;

    public int $flags = 0;

    public float $x = 0.0;
    public float $y = 0.0;
    public float $z = 0.0;

    public float $pitch = 0.0;
    public float $yaw = 0.0;
    public float $headYaw = 0.0;

    public static function create(int $actorRuntimeId, float $x, float $y, float $z, float $pitch, float $yaw, float $headYaw, int $flags = 0) : self{
        $packet = new self();
        $packet->actorRuntimeId = $actorRuntimeId;
        $packet->x = $x;
        $packet->y = $y;
        $packet->z = $z;
        $packet->pitch = $pitch;
        $packet->yaw = $yaw;
        $packet->headYaw = $headYaw;
        $packet->flags = $flags;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::MOVE_ACTOR_ABSOLUTE_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeUnsignedVarLong($this->actorRuntimeId);
        $writer->writeByte($this->flags);
        $writer->writeFloat($this->x);
        $writer->writeFloat($this->y);
        $writer->writeFloat($this->z);
        $writer->writeByte(self::angleToByte($this->pitch));
        $writer->writeByte(self::angleToByte($this->yaw));
        $writer->writeByte(self::angleToByte($this->headYaw));
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->actorRuntimeId = $reader->readUnsignedVarLong();
        $this->flags = $reader->readByte();
        $this->x = $reader->readFloat();
        $this->y = $reader->readFloat();
        $this->z = $reader->readFloat();
        $this->pitch = $reader->readByte() * (360 / 256);
        $this->yaw = $reader->readByte() * (360 / 256);
        $this->headYaw = $reader->readByte() * (360 / 256);
    }

    private static function angleToByte(float $degrees) : int{
        return ((int) round($degrees / (360 / 256))) & 0xff;
    }
}