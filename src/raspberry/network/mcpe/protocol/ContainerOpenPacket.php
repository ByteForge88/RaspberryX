<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

class ContainerOpenPacket implements Packet{

    /** the player's own inventory */
    public const TYPE_INVENTORY = -1;

    public int $windowId = 0;

    public int $type = self::TYPE_INVENTORY;

    public int $x = 0;
    public int $y = 0;
    public int $z = 0;

    /** the entity the container belongs to, -1 for none */
    public int $actorUniqueId = -1;

    public static function create(int $windowId, int $type, int $x, int $y, int $z, int $actorUniqueId) : self{
        $packet = new self();
        $packet->windowId = $windowId;
        $packet->type = $type;
        $packet->x = $x;
        $packet->y = $y;
        $packet->z = $z;
        $packet->actorUniqueId = $actorUniqueId;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::CONTAINER_OPEN_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeByte($this->windowId);
        $writer->writeByte($this->type & 0xff);
        $writer->writeVarInt($this->x);
        $writer->writeVarInt($this->y);
        $writer->writeVarInt($this->z);
        $writer->writeVarLong($this->actorUniqueId);
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("ContainerOpenPacket is server-to-client only");
    }
}