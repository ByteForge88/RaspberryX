<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use function count;

class UpdateSoftEnumPacket implements Packet{

    public const TYPE_ADD = 0;
    public const TYPE_REMOVE = 1;
    public const TYPE_SET = 2;

    public string $enumName = "";

    /** @var list<string> */
    public array $values = [];

    public int $type = self::TYPE_SET;

    /**
     * @param list<string> $values
     */
    public static function create(string $enumName, array $values, int $type) : self{
        $packet = new self();
        $packet->enumName = $enumName;
        $packet->values = $values;
        $packet->type = $type;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::UPDATE_SOFT_ENUM_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeString($this->enumName);
        $writer->writeUnsignedVarInt(count($this->values));

        foreach($this->values as $value){
            $writer->writeString($value);
        }

        $writer->writeByte($this->type);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->enumName = $reader->readString();

        for($i = 0, $count = $reader->readUnsignedVarInt(); $i < $count; ++$i){
            $this->values[] = $reader->readString();
        }

        $this->type = $reader->readByte();
    }
}