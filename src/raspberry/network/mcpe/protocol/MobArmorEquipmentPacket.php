<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use raspberry\network\mcpe\protocol\types\ItemInstance;
use raspberry\network\mcpe\serializer\ItemStackSerializer;

use UnexpectedValueException;

class MobArmorEquipmentPacket implements Packet{

    public int $actorRuntimeId = 0;

    public ItemInstance $helmet;
    public ItemInstance $chestplate;
    public ItemInstance $leggings;
    public ItemInstance $boots;

    /** horse and wolf armor, empty for players */
    public ItemInstance $body;

    public function __construct(){
        $this->helmet = $this->chestplate = $this->leggings = $this->boots = $this->body = ItemInstance::empty();
    }

    public static function create(int $actorRuntimeId, ItemInstance $helmet, ItemInstance $chestplate, ItemInstance $leggings, ItemInstance $boots) : self{
        $packet = new self();
        $packet->actorRuntimeId = $actorRuntimeId;
        $packet->helmet = $helmet;
        $packet->chestplate = $chestplate;
        $packet->leggings = $leggings;
        $packet->boots = $boots;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::MOB_ARMOR_EQUIPMENT_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeUnsignedVarLong($this->actorRuntimeId);

        foreach([$this->helmet, $this->chestplate, $this->leggings, $this->boots, $this->body] as $item){
            ItemStackSerializer::writeInstance($writer, $item->stack, $item->stackNetworkId);
        }
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("MobArmorEquipmentPacket from clients is not used");
    }
}