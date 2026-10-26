<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class DisconnectPacket implements Packet{

    /** generic "disconnected" reason, Bedrock has many more codes for telemetry */
    public const REASON_UNKNOWN = 0;

    public int $reason = self::REASON_UNKNOWN;

    public bool $hideDisconnectScreen = false;

    public string $message = "";

    public string $filteredMessage = "";

    public static function create(string $message, int $reason = self::REASON_UNKNOWN) : self{
        $packet = new self();
        $packet->message = $message;
        $packet->reason = $reason;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::DISCONNECT_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeVarInt($this->reason);
        $writer->writeBool($this->hideDisconnectScreen);

        if(!$this->hideDisconnectScreen){
            $writer->writeString($this->message);
            $writer->writeString($this->filteredMessage);
        }
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->reason = $reader->readVarInt();
        $this->hideDisconnectScreen = $reader->readBool();

        if(!$this->hideDisconnectScreen){
            $this->message = $reader->readString();
            $this->filteredMessage = $reader->readString();
        }
    }
}