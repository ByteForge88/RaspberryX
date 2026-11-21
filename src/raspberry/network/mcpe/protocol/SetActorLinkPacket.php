<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

class SetActorLinkPacket implements Packet{

    public const int TYPE_REMOVE = 0;
    public const int TYPE_RIDER = 1;
    public const int TYPE_PASSENGER = 2;

    public int $vehicleId = 0;

    public int $riderId = 0;

    public int $type = self::TYPE_REMOVE;

    public bool $immediate = false;

    public bool $riderInitiated = false;

    public float $vehicleAngularVelocity = 0.0;

    public static function create(int $vehicleId, int $riderId, int $type, bool $immediate = false, bool $riderInitiated = false) : self{
        $packet = new self();
        $packet->vehicleId = $vehicleId;
        $packet->riderId = $riderId;
        $packet->type = $type;
        $packet->immediate = $immediate;
        $packet->riderInitiated = $riderInitiated;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::SET_ACTOR_LINK_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeVarLong($this->vehicleId);
        $writer->writeVarLong($this->riderId);
        $writer->writeByte($this->type);
        $writer->writeBool($this->immediate);
        $writer->writeBool($this->riderInitiated);
        $writer->writeFloat($this->vehicleAngularVelocity);
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("SetActorLinkPacket is server-to-client only");
    }
}