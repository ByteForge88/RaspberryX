<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;
use raspberry\network\mcpe\serializer\NetworkNbtWriter;

use UnexpectedValueException;

use function count;

class ItemRegistryPacket implements Packet{

    /** item entry versions */
    public const VERSION_LEGACY = 0;
    public const VERSION_DATA_DRIVEN = 1;
    public const VERSION_NONE = 2;

    /** @var array<string, int> item name => runtime ID */
    public array $items = [];

    /**
     * @param array<string, int> $items
     */
    public static function create(array $items) : self{
        $packet = new self();
        $packet->items = $items;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::ITEM_REGISTRY_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $emptyNbt = NetworkNbtWriter::emptyCompound();

        $writer->writeUnsignedVarInt(count($this->items));

        foreach($this->items as $name => $runtimeId){
            $writer->writeString($name);
            $writer->writeShort($runtimeId);
            $writer->writeBool(false);
            $writer->writeVarInt(self::VERSION_NONE);
            $writer->writeBytes($emptyNbt);
        }
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("ItemRegistryPacket is server-to-client only");
    }
}