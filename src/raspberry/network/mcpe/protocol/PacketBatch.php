<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use InvalidArgumentException;
use UnexpectedValueException;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use function count;
use function is_string;
use function strlen;

class PacketBatch{

    public const MAX_PACKET_BYTES = 2 * 1024 * 1024;
    public const MAX_BATCH_BYTES = 8 * 1024 * 1024;
    public const MAX_PACKETS = 512;

    private PacketTranslator $translator;

    public function __construct(PacketTranslator $translator){
        $this->translator = $translator;
    }

    /**
     * Encodes packet objects into an uncompressed batch.
     *
     * All packets in this call use the supplied subclient IDs.
     *
     * @param list<Packet> $packets
     */
    public function encode(array $packets, int $senderSubClientId = 0, int $targetSubClientId = 0) : string{
        $this->checkPacketCount(count($packets));

        $encodedPackets = [];
        $totalBytes = 0;

        foreach($packets as $packet){
            if(!$packet instanceof Packet){
                throw new InvalidArgumentException("Batch entries must implement Packet");
            }

            $encoded = $this->translator->encodePayload($packet, $senderSubClientId, $targetSubClientId);

            $totalBytes += strlen($encoded);

            if($totalBytes > self::MAX_BATCH_BYTES){
                throw new UnexpectedValueException("Encoded packets exceed the maximum batch size");
            }

            $encodedPackets[] = $encoded;
        }

        return $this->encodeRaw($encodedPackets);
    }

    /**
     * Frames already encoded packets.
     *
     * Each string must include the packet header produced by
     * PacketTranslator, followed by that packet's payload.
     *
     * @param list<string> $packets
     */
    public function encodeRaw(array $packets) : string{
        $this->checkPacketCount(count($packets));

        $writer = new BinaryWriter();
        $totalBytes = 0;

        foreach($packets as $packet){
            if(!is_string($packet)){
                throw new InvalidArgumentException("Raw batch entries must be strings");
            }

            $length = strlen($packet);

            $this->checkPacketLength($length);

            $prefix = new BinaryWriter();
            $prefix->writeUnsignedVarInt($length);

            $lengthBytes = $prefix->getBuffer();
            $totalBytes += strlen($lengthBytes) + $length;

            if($totalBytes > self::MAX_BATCH_BYTES){
                throw new UnexpectedValueException("Encoded batch exceeds the maximum batch size");
            }

            $writer->writeBytes($lengthBytes);
            $writer->writeBytes($packet);
        }

        return $writer->getBuffer();
    }

    /**
     * Splits an uncompressed batch into individual encoded packets.
     *
     * This method does not require registered packet classes.
     *
     * @return list<string>
     */
    public function decodeRaw(string $buffer) : array{
        $length = strlen($buffer);

        if($length === 0){
            throw new UnexpectedValueException("Received an empty batch");
        }

        if($length > self::MAX_BATCH_BYTES){
            throw new UnexpectedValueException("Received batch exceeds the maximum batch size");
        }

        $reader = new BinaryReader($buffer);
        $packets = [];

        while(!$reader->isFinished()){
            if(count($packets) >= self::MAX_PACKETS){
                throw new UnexpectedValueException("Received batch contains too many packets");
            }

            $packetLength = $reader->readUnsignedVarInt();

            $this->checkPacketLength($packetLength);

            if($packetLength > $reader->remaining()){
                throw new UnexpectedValueException("Truncated batch: packet length exceeds remaining bytes");
            }

            $packets[] = $reader->readBytes($packetLength);
        }

        return $packets;
    }

    /**
     * Splits a batch and decodes its registered packet classes.
     *
     * @return list<array{
     *     packet: Packet,
     *     senderSubClientId: int,
     *     targetSubClientId: int
     * }>
     */
    public function decode(string $buffer) : array{
        $decoded = [];

        foreach($this->decodeRaw($buffer) as $packet){
            $decoded[] = $this->translator->decode($packet);
        }

        return $decoded;
    }

    private function checkPacketCount(int $count) : void{
        if($count < 1 || $count > self::MAX_PACKETS){
            throw new InvalidArgumentException("A batch must contain between 1 and " . self::MAX_PACKETS . " packets");
        }
    }

    private function checkPacketLength(int $length) : void{
        if($length < 1){
            throw new UnexpectedValueException("A packet cannot be empty");
        }

        if($length > self::MAX_PACKET_BYTES){
            throw new UnexpectedValueException("Packet exceeds the maximum packet size");
        }
    }
}