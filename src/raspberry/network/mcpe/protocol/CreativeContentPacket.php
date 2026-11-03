<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use raspberry\network\mcpe\protocol\types\ItemStack;
use raspberry\network\mcpe\serializer\ItemStackSerializer;

use UnexpectedValueException;

use function count;

class CreativeContentPacket implements Packet{

    public const CATEGORY_CONSTRUCTION = 1;
    public const CATEGORY_NATURE = 2;
    public const CATEGORY_EQUIPMENT = 3;
    public const CATEGORY_ITEMS = 4;

    /** @var list<array{int, string, ItemStack}> category, name, icon */
    public array $groups = [];

    /** @var list<array{ItemStack, int}> item, group index */
    public array $items = [];

    public static function getPacketId() : int{
        return ProtocolPacketIds::CREATIVE_CONTENT_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeUnsignedVarInt(count($this->groups));

        foreach($this->groups as [$category, $name, $icon]){
            $writer->writeByte($category);
            $writer->writeString($name);
            ItemStackSerializer::writeStack($writer, $icon);
        }

        $writer->writeUnsignedVarInt(count($this->items));

        foreach($this->items as $index => [$item, $group]){
            $writer->writeUnsignedVarInt($index + 1); //creative item network ID
            ItemStackSerializer::writeStack($writer, $item);
            $writer->writeUnsignedVarInt($group);
        }
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("CreativeContentPacket is server-to-client only");
    }
}