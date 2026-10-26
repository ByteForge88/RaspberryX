<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;
use raspberry\network\mcpe\serializer\NetworkNbtWriter;

use UnexpectedValueException;

/**
 * Client -> server, every tick: the player's movement and input.
 *
 * Only the leading rotation and position fields are decoded for now; the rest of the packet
 * (input flags, block actions, item interactions...) is skipped.
 * TODO: decode fully when movement validation and block breaking are implemented
 */
class PlayerAuthInputPacket implements Packet{

    public float $pitch = 0.0;
    public float $yaw = 0.0;

    /** eye position */
    public float $x = 0.0;
    public float $y = 0.0;
    public float $z = 0.0;

    public static function getPacketId() : int{
        return ProtocolPacketIds::PLAYER_AUTH_INPUT_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        throw new UnexpectedValueException("PlayerAuthInputPacket is client-to-server only");
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->pitch = $reader->readFloat();
        $this->yaw = $reader->readFloat();
        $this->x = $reader->readFloat();
        $this->y = $reader->readFloat();
        $this->z = $reader->readFloat();
        $reader->readBytes($reader->remaining());
    }
}