<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use raspberry\network\mcpe\protocol\types\AbilityData;

class UpdateAbilitiesPacket implements Packet{

    public AbilityData $data;

    public static function create(AbilityData $data) : self{
        $packet = new self();
        $packet->data = $data;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::UPDATE_ABILITIES_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $this->data->write($writer);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->data = AbilityData::read($reader);
    }
}