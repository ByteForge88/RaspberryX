<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use InvalidArgumentException;
use UnexpectedValueException;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

class PacketTranslator{

    private const PACKET_ID_MASK = 0x3ff;
    private const SUBCLIENT_MASK = 0x03;

    private const SENDER_SHIFT = 10;
    private const TARGET_SHIFT = 12;

    private const HEADER_MASK = 0x3fff;

    private PacketRegistry $registry;

    private int $maxPacketBytes;

    public function __construct(PacketRegistry $registry, int $maxPacketBytes = 2097152){
        $this->registry = $registry;
        $this->maxPacketBytes = $maxPacketBytes;

        if($maxPacketBytes < 1){
            throw new InvalidArgumentException("Maximum packet size must be positive");
        }
    }

    public function encodePayload(Packet $packet, int $senderSubClientId = 0, int $targetSubClientId = 0) : string{
        self::checkSubClientId($senderSubClientId);
        self::checkSubClientId($targetSubClientId);

        $this->registry->assertRegistered($packet);

        $header = $packet::getPacketId() | ($senderSubClientId << self::SENDER_SHIFT) | ($targetSubClientId << self::TARGET_SHIFT);

        $writer = new BinaryWriter();
        $writer->writeUnsignedVarInt($header);

        $packet->encodePayload($writer);

        $buffer = $writer->getBuffer();

        if(strlen($buffer) > $this->maxPacketBytes){
            throw new UnexpectedValueException("Encoded packet exceeds the maximum packet size");
        }

        return $buffer;
    }

    /**
     * The input must contain exactly one complete packet.
     *
     * @return array{
     *     packet: Packet,
     *     senderSubClientId: int,
     *     targetSubClientId: int
     * }
     */
    public function decode(string $buffer) : array{
        if(strlen($buffer) > $this->maxPacketBytes){
            throw new UnexpectedValueException("Received packet exceeds the maximum packet size");
        }

        $reader = new BinaryReader($buffer);
        $header = $reader->readUnsignedVarInt();

        if(($header & ~self::HEADER_MASK) !== 0){
            throw new UnexpectedValueException("Packet header contains unsupported bits");
        }

        $packetId = $header & self::PACKET_ID_MASK;

        $senderSubClientId = ($header >> self::SENDER_SHIFT) & self::SUBCLIENT_MASK;

        $targetSubClientId = ($header >> self::TARGET_SHIFT) & self::SUBCLIENT_MASK;

        $packet = $this->registry->create($packetId);
        $packet->decodePayload($reader);

        if(!$reader->isFinished()){
            throw new UnexpectedValueException("Packet {$packetId} has {$reader->remaining()} unread bytes");
        }

        return [
            "packet" => $packet,
            "senderSubClientId" => $senderSubClientId,
            "targetSubClientId" => $targetSubClientId
        ];
    }

    private static function checkSubClientId(int $id) : void{
        if($id < 0 || $id > self::SUBCLIENT_MASK){
            throw new InvalidArgumentException("Subclient ID must be between 0 and 3");
        }
    }
}