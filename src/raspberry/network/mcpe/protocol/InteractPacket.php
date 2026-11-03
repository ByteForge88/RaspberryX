<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

class InteractPacket implements Packet{

    public const ACTION_LEAVE_VEHICLE = 3;
    public const ACTION_MOUSE_OVER_ENTITY = 4;
    public const ACTION_OPEN_NPC = 5;
    public const ACTION_OPEN_INVENTORY = 6;

    public int $action = 0;

    public int $targetActorRuntimeId = 0;

    /** @var array{float, float, float}|null */
    public ?array $position = null;

    public static function getPacketId() : int{
        return ProtocolPacketIds::INTERACT_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        throw new UnexpectedValueException("InteractPacket is client-to-server only");
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->action = $reader->readByte();
        $this->targetActorRuntimeId = $reader->readUnsignedVarLong();
        $this->position = $reader->readBool() ? [$reader->readFloat(), $reader->readFloat(), $reader->readFloat()] : null;
    }
}