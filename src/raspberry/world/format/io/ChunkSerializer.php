<?php

declare(strict_types=1);

namespace raspberry\world\format\io;

use raspberry\block\BlockState;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use raspberry\world\Chunk;
use raspberry\world\format\SubChunk;
use raspberry\world\format\BiomeStorage;
use raspberry\world\format\BlockStorage;
use raspberry\world\format\PalettedStorage;

use Throwable;
use UnexpectedValueException;

use function chr;
use function count;
use function is_bool;
use function is_int;
use function ord;
use function strlen;
use function substr;
use function zlib_decode;
use function zlib_encode;

/**
 * File: "RXCH" + version byte + zlib-compressed body. The body (little-endian):
 *   int32 chunkX, int32 chunkZ, byte subChunkCount
 *   per sub-chunk: byte y (signed), byte layerCount,
 *                  layers:  uvarint paletteSize, palette entries, [8192 bytes indices if paletteSize > 1]
 *                  biomes:  uvarint paletteSize, uint16 per entry,  [8192 bytes indices if paletteSize > 1]
 *   block palette entry: string name, byte propertyCount, per property: string name, byte type, value
 *     type 0 = bool (byte), 1 = int (int32), 2 = string
 *
 * This is only the disk format. Sending chunks to players uses a separate network serializer (Step 7)
 */
final class ChunkSerializer{

    private const MAGIC = "RXCH";
    private const VERSION = 1;

    private const TYPE_BOOL = 0;
    private const TYPE_INT = 1;
    private const TYPE_STRING = 2;

    /** decompressed chunks above this size are treated as corrupt */
    private const MAX_BODY_SIZE = 64 * 1024 * 1024;

    public static function serialize(Chunk $chunk) : string{
        $chunk->collectGarbage();

        $writer = new BinaryWriter();
        $writer->writeInt($chunk->getX());
        $writer->writeInt($chunk->getZ());

        $subChunks = $chunk->getSubChunks();
        $writer->writeByte(count($subChunks));

        foreach($subChunks as $y => $subChunk){
            $writer->writeByte($y & 0xff);

            $layers = $subChunk->getBlockLayers();
            $writer->writeByte(count($layers));

            foreach($layers as $layer){
                $palette = $layer->getPalette();
                $writer->writeUnsignedVarInt(count($palette));

                foreach($palette as $state){
                    self::writeBlockState($writer, $state);
                }

                self::writeIndices($writer, $layer);
            }

            $biomes = $subChunk->getBiomeStorage();
            $biomePalette = $biomes->getPalette();
            $writer->writeUnsignedVarInt(count($biomePalette));

            foreach($biomePalette as $biomeId){
                $writer->writeUnsignedShort($biomeId);
            }

            self::writeIndices($writer, $biomes);
        }

        $compressed = zlib_encode($writer->getBuffer(), ZLIB_ENCODING_DEFLATE, 6);

        if($compressed === false){
            throw new UnexpectedValueException("Failed to compress chunk {$chunk->getX()}, {$chunk->getZ()}");
        }

        return self::MAGIC . chr(self::VERSION) . $compressed;
    }

    /**
     * @throws CorruptChunkException when the data is damaged or belongs to another chunk
     */
    public static function deserialize(string $data, int $expectedX, int $expectedZ) : Chunk{
        try{
            if(strlen($data) < 5 || substr($data, 0, 4) !== self::MAGIC){
                throw new UnexpectedValueException("Not a RaspberryX chunk file");
            }

            $version = ord($data[4]);

            if($version !== self::VERSION){
                throw new UnexpectedValueException("Unsupported chunk format version {$version}");
            }

            $body = @zlib_decode(substr($data, 5), self::MAX_BODY_SIZE);

            if($body === false){
                throw new UnexpectedValueException("Chunk data cannot be decompressed");
            }

            $reader = new BinaryReader($body);

            $x = $reader->readInt();
            $z = $reader->readInt();

            if($x !== $expectedX || $z !== $expectedZ){
                throw new UnexpectedValueException("File contains chunk {$x}, {$z}");
            }

            $chunk = new Chunk($x, $z);
            $subChunkCount = $reader->readByte();

            for($i = 0; $i < $subChunkCount; ++$i){
                $y = $reader->readByte();
                $y = $y >= 0x80 ? $y - 0x100 : $y;//signed byte

                $layerCount = $reader->readByte();
                $layers = [];

                for($layer = 0; $layer < $layerCount; ++$layer){
                    $palette = [];
                    $paletteSize = self::readPaletteSize($reader);

                    for($entry = 0; $entry < $paletteSize; ++$entry){
                        $palette[] = self::readBlockState($reader);
                    }

                    $layers[] = BlockStorage::fromData($palette, self::readIndices($reader, $paletteSize));
                }

                $biomePalette = [];
                $biomePaletteSize = self::readPaletteSize($reader);

                for($entry = 0; $entry < $biomePaletteSize; ++$entry){
                    $biomePalette[] = $reader->readUnsignedShort();
                }

                $biomes = BiomeStorage::fromData($biomePalette, self::readIndices($reader, $biomePaletteSize));

                $chunk->setSubChunk($y, SubChunk::fromStorages($layers, $biomes));
            }

            if(!$reader->isFinished()){
                throw new UnexpectedValueException("Unexpected data after the last sub-chunk");
            }
        }catch(Throwable $error){
            throw new CorruptChunkException("Chunk {$expectedX}, {$expectedZ} is corrupt: " . $error->getMessage(), 0, $error);
        }

        $chunk->setChanged(false);

        return $chunk;
    }

    private static function writeBlockState(BinaryWriter $writer, BlockState $state) : void{
        $writer->writeString($state->getName());

        $properties = $state->getProperties();
        $writer->writeByte(count($properties));

        foreach($properties as $name => $value){
            $writer->writeString($name);

            if(is_bool($value)){
                $writer->writeByte(self::TYPE_BOOL);
                $writer->writeBool($value);
            }elseif(is_int($value)){
                $writer->writeByte(self::TYPE_INT);
                $writer->writeInt($value);
            }else{
                $writer->writeByte(self::TYPE_STRING);
                $writer->writeString($value);
            }
        }
    }

    private static function readBlockState(BinaryReader $reader) : BlockState{
        $name = $reader->readString(256);
        $propertyCount = $reader->readByte();
        $properties = [];

        for($i = 0; $i < $propertyCount; ++$i){
            $property = $reader->readString(256);

            $properties[$property] = match($type = $reader->readByte()){
                self::TYPE_BOOL => $reader->readBool(),
                self::TYPE_INT => $reader->readInt(),
                self::TYPE_STRING => $reader->readString(256),
                default => throw new UnexpectedValueException("Unknown property type {$type} in {$name}")
            };
        }

        return new BlockState($name, $properties);
    }

    private static function writeIndices(BinaryWriter $writer, PalettedStorage $storage) : void{
        $indices = $storage->getRawIndices();

        if($indices !== null){
            $writer->writeBytes($indices);
        }
    }

    private static function readIndices(BinaryReader $reader, int $paletteSize) : ?string{
        return $paletteSize > 1 ? $reader->readBytes(PalettedStorage::SIZE * 2) : null;
    }

    private static function readPaletteSize(BinaryReader $reader) : int{
        $size = $reader->readUnsignedVarInt();

        if($size < 1 || $size > PalettedStorage::SIZE){
            throw new UnexpectedValueException("Invalid palette size {$size}");
        }

        return $size;
    }
}