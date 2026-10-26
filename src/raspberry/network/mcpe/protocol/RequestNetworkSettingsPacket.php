<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class RequestNetworkSettingsPacket implements Packet{

    public int $protocolVersion = 0;

    public static function getPacketId() : int{
        return ProtocolPacketIds::REQUEST_NETWORK_SETTINGS_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeInt($this->protocolVersion, false);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->protocolVersion = $reader->readInt(false);
    }
}