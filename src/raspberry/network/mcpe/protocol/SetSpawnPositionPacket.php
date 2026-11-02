<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

class SetSpawnPositionPacket implements Packet{

    public const TYPE_PLAYER_SPAWN = 0;
    public const TYPE_WORLD_SPAWN = 1;

    public int $type = self::TYPE_WORLD_SPAWN;

    public int $x = 0;
    public int $y = 0;
    public int $z = 0;

    public int $dimension = 0;

    public static function create(int $type, int $x, int $y, int $z) : self{
        $packet = new self();
        $packet->type = $type;
        $packet->x = $x;
        $packet->y = $y;
        $packet->z = $z;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::SET_SPAWN_POSITION_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeVarInt($this->type);
        $this->writeBlockPosition($writer);
        $writer->writeVarInt($this->dimension);
        $this->writeBlockPosition($writer);
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("SetSpawnPositionPacket is server-to-client only");
    }

    private function writeBlockPosition(BinaryWriter $writer) : void{
        $writer->writeVarInt($this->x);
        $writer->writeVarInt($this->y);
        $writer->writeVarInt($this->z);
    }
}