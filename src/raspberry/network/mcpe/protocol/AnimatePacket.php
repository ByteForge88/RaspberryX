<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

class AnimatePacket implements Packet{

    public const int ACTION_SWING_ARM = 1;
    public const int ACTION_STOP_SLEEP = 3;
    public const int ACTION_CRITICAL_HIT = 4;
    public const int ACTION_MAGIC_CRITICAL_HIT = 5;

    public const string SWING_BUILD = "build";
    public const string SWING_MINE = "mine";
    public const string SWING_INTERACT = "interact";
    public const string SWING_ATTACK = "attack";

    public int $action = self::ACTION_SWING_ARM;

    public int $actorRuntimeId = 0;

    public float $data = 0.0;

    /** why the arm swings, null for none */
    public ?string $swingSource = null;

    /** 0: main hand, 1: off hand */
    public int $hand = 0;

    public static function create(int $action, int $actorRuntimeId, ?string $swingSource = null) : self{
        $packet = new self();
        $packet->action = $action;
        $packet->actorRuntimeId = $actorRuntimeId;
        $packet->swingSource = $swingSource;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::ANIMATE_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeByte($this->action);
        $writer->writeUnsignedVarLong($this->actorRuntimeId);
        $writer->writeFloat($this->data);
        $writer->writeBool($this->swingSource !== null);

        if($this->swingSource !== null){
            $writer->writeString($this->swingSource);
        }

        $writer->writeByte($this->hand);
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("AnimatePacket is only sent by the server");
    }
}