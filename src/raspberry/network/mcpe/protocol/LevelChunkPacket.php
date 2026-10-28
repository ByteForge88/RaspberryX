<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\protocol;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use UnexpectedValueException;

class LevelChunkPacket implements Packet{

    //TODO: Add DimenionIds.php
    public const DIMENSION_OVERWORLD = 0;

    public int $chunkX = 0;
    public int $chunkZ = 0;

    public int $dimension = self::DIMENSION_OVERWORLD;

    public int $subChunkCount = 0;

    public string $payload = "";

    public static function create(int $chunkX, int $chunkZ, int $subChunkCount, string $payload) : self{
        $packet = new self();
        $packet->chunkX = $chunkX;
        $packet->chunkZ = $chunkZ;
        $packet->subChunkCount = $subChunkCount;
        $packet->payload = $payload;
        return $packet;
    }

    public static function getPacketId() : int{
        return ProtocolPacketIds::LEVEL_CHUNK_PACKET;
    }

    public function encodePayload(BinaryWriter $writer) : void{
        $writer->writeVarInt($this->chunkX);
        $writer->writeVarInt($this->chunkZ);
        $writer->writeVarInt($this->dimension);
        $writer->writeUnsignedVarInt($this->subChunkCount);
        $writer->writeBool(false);
        $writer->writeBool(false);
        $writer->writeUnsignedVarInt(0);
        $writer->writeString($this->payload, 16 * 1024 * 1024);
        $writer->writeBool(false); //client biome update: keep the client's biomes (26.60)
    }

    public function decodePayload(BinaryReader $reader) : void{
        throw new UnexpectedValueException("LevelChunkPacket is server-to-client only");
    }
}