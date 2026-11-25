<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class ServerboundLoadingScreenPacket implements Packet{

    public const int TYPE_UNKNOWN = 0;
    public const int TYPE_START = 1;
    public const int TYPE_END = 2;

    public int $type = self::TYPE_UNKNOWN;

    /** the ID the server gave the loading screen in ChangeDimensionPacket */
    public ?int $loadingScreenId = null;

    public static function getPacketId() : int{
        return ProtocolPacketIds::SERVERBOUND_LOADING_SCREEN_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeVarInt($this->type);
        $writer->writeBool($this->loadingScreenId !== null);

        if($this->loadingScreenId !== null){
            $writer->writeUnsignedInt($this->loadingScreenId);
        }
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->type = $reader->readVarInt();
        $this->loadingScreenId = $reader->readBool() ? $reader->readUnsignedInt() : null;
    }
}