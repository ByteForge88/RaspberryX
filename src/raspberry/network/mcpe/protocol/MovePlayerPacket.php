<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

class MovePlayerPacket implements Packet{

    public const MODE_NORMAL = 0;
    public const MODE_RESET = 1;
    public const MODE_TELEPORT = 2;
    public const MODE_ROTATION = 3;

    public const TELEPORT_CAUSE_UNKNOWN = 0;
    public const TELEPORT_CAUSE_PROJECTILE = 1;
    public const TELEPORT_CAUSE_CHORUS_FRUIT = 2;
    public const TELEPORT_CAUSE_COMMAND = 3;
    public const TELEPORT_CAUSE_BEHAVIOR = 4;

    public int $actorRuntimeId = 0;

    /** eye position */
    public float $x = 0.0;
    public float $y = 0.0;
    public float $z = 0.0;

    public float $pitch = 0.0;
    public float $yaw = 0.0;
    public float $headYaw = 0.0;

    public int $mode = self::MODE_NORMAL;

    public bool $onGround = false;

    public int $ridingActorRuntimeId = 0;

    public int $teleportCause = self::TELEPORT_CAUSE_UNKNOWN;

    public int $teleportSourceActorType = 0;

    public int $tick = 0;

    public static function teleport(int $actorRuntimeId, float $x, float $y, float $z, float $pitch, float $yaw, int $cause, int $tick) : self{
        $packet = new self();
        $packet->actorRuntimeId = $actorRuntimeId;
        $packet->x = $x;
        $packet->y = $y;
        $packet->z = $z;
        $packet->pitch = $pitch;
        $packet->yaw = $yaw;
        $packet->headYaw = $yaw;
        $packet->mode = self::MODE_TELEPORT;
        $packet->teleportCause = $cause;
        $packet->tick = $tick;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::MOVE_PLAYER_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeUnsignedVarLong($this->actorRuntimeId);
        $writer->writeFloat($this->x);
        $writer->writeFloat($this->y);
        $writer->writeFloat($this->z);
        $writer->writeFloat($this->pitch);
        $writer->writeFloat($this->yaw);
        $writer->writeFloat($this->headYaw);
        $writer->writeByte($this->mode);
        $writer->writeBool($this->onGround);
        $writer->writeUnsignedVarLong($this->ridingActorRuntimeId);
        $writer->writeBool($this->mode === self::MODE_TELEPORT);

        if($this->mode === self::MODE_TELEPORT){
            $writer->writeInt($this->teleportCause);
            $writer->writeInt($this->teleportSourceActorType);
        }

        $writer->writeUnsignedVarLong($this->tick);
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("MovePlayerPacket from clients is not used, they send PlayerAuthInputPacket");
    }
}