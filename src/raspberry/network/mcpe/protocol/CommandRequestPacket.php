<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use raspberry\network\mcpe\protocol\types\command\CommandOriginData;

class CommandRequestPacket implements Packet{

    /** commands longer than this are refused without reading them */
    public const MAX_LENGTH = 32768;

    public string $command = "";

    public CommandOriginData $origin;

    /** true when the client ran the command itself, not the player */
    public bool $internal = false;

    public string $version = "";

    public static function getPacketId() : int{
        return ProtocolPacketIds::COMMAND_REQUEST_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeString($this->command, self::MAX_LENGTH);
        $this->origin->write($writer);
        $writer->writeBool($this->internal);
        $writer->writeString($this->version, 64);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->command = $reader->readString(self::MAX_LENGTH);
        $this->origin = CommandOriginData::read($reader);
        $this->internal = $reader->readBool();
        $this->version = $reader->readString(64);
    }
}