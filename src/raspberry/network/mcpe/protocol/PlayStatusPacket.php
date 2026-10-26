<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class PlayStatusPacket implements Packet{

    public const LOGIN_SUCCESS = 0;
    public const LOGIN_FAILED_CLIENT_OLD = 1;
    public const LOGIN_FAILED_SERVER_OLD = 2;
    public const PLAYER_SPAWN = 3;

    public int $status = self::LOGIN_SUCCESS;

    public static function getPacketId() : int{
        return ProtocolPacketIds::PLAY_STATUS_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeInt($this->status, false);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->status = $reader->readInt(false);
    }
}