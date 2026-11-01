<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class SetPlayerGameTypePacket implements Packet{

    public int $gameMode = 0;

    public static function create(int $gameMode) : self{
        $packet = new self();
        $packet->gameMode = $gameMode;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::SET_PLAYER_GAME_TYPE_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeVarInt($this->gameMode);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->gameMode = $reader->readVarInt();
    }
}