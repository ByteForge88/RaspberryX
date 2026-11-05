<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

class ContainerSetDataPacket implements Packet{

    public const FURNACE_TICK_COUNT = 0;
    public const FURNACE_LIT_TIME = 1;
    public const FURNACE_LIT_DURATION = 2;

    public const BREWING_STAND_BREW_TIME = 0;
    public const BREWING_STAND_FUEL_AMOUNT = 1;
    public const BREWING_STAND_FUEL_TOTAL = 2;

    public int $windowId = 0;

    public int $key = 0;

    public int $value = 0;

    public static function create(int $windowId, int $key, int $value) : self{
        $packet = new self();
        $packet->windowId = $windowId;
        $packet->key = $key;
        $packet->value = $value;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::CONTAINER_SET_DATA_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeByte($this->windowId);
        $writer->writeVarInt($this->key);
        $writer->writeVarInt($this->value);
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("ContainerSetDataPacket is only sent by the server");
    }
}