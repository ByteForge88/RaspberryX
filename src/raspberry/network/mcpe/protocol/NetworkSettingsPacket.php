<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class NetworkSettingsPacket implements Packet{

    public const ALGORITHM_ZLIB = 0;
    public const ALGORITHM_SNAPPY = 1;

    public int $compressionThreshold = 256;
    public int $compressionAlgorithm = self::ALGORITHM_ZLIB;

    public bool $clientThrottleEnabled = false;
    public int $clientThrottleThreshold = 0;
    public float $clientThrottleScalar = 0.0;

    public static function getPacketId() : int{
        return ProtocolPacketIds::NETWORK_SETTINGS_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeUnsignedShort($this->compressionThreshold);
        $writer->writeUnsignedShort($this->compressionAlgorithm);

        $writer->writeBool($this->clientThrottleEnabled);
        $writer->writeByte($this->clientThrottleThreshold);
        $writer->writeFloat($this->clientThrottleScalar);
    }

    public function decodePayload(BinaryReader $reader) : void{
        $this->compressionThreshold = $reader->readUnsignedShort();
        $this->compressionAlgorithm = $reader->readUnsignedShort();

        $this->clientThrottleEnabled = $reader->readBool();
        $this->clientThrottleThreshold = $reader->readByte();
        $this->clientThrottleScalar = $reader->readFloat();
    }
}