<?php

declare(strict_types=1);

namespace raspberry\world\format\io;

use raspberry\block\BlockState;
use raspberry\block\BlockStateIds;
use raspberry\block\entity\BlockEntityFactory;

use raspberry\nbt\NbtSerializer;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use raspberry\world\Chunk;

use Throwable;
use UnexpectedValueException;

use function chr;
use function array_values;
use function count;
use function is_bool;
use function is_int;
use function max;
use function ord;
use function pack;
use function strlen;
use function substr;
use function unpack;
use function zlib_decode;
use function zlib_encode;

final class ChunkSerializer{

    private const MAGIC = "RXCH";
    private const VERSION = 1;

    private const TYPE_BOOL = 0;
    private const TYPE_INT = 1;
    private const TYPE_STRING = 2;

    /** blocks or biomes in a sub-chunk */
    private const STORAGE_SIZE = 4096;

    /** decompressed chunks above this size are treated as corrupt */
    private const MAX_BODY_SIZE = 64 * 1024 * 1024;

    public static function serialize(Chunk $chunk) : string{
        $chunk->collectGarbage();

        $writer = new BinaryWriter();
        $writer->writeInt($chunk->getX());
        $writer->writeInt($chunk->getZ());

        $storage = new BinaryReader($chunk->exportStorage());
        $writer->writeByte(Chunk::MAX_SUBCHUNK - Chunk::MIN_SUBCHUNK + 1);

        for($y = Chunk::MIN_SUBCHUNK; $y <= Chunk::MAX_SUBCHUNK; ++$y){
            $writer->writeByte($y & 0xff);

            $layerCount = $storage->readByte();
            $writer->writeByte($layerCount);

            for($layer = 0; $layer < $layerCount; ++$layer){
                [$palette, $indices] = self::readExported($storage);
                $writer->writeUnsignedVarInt(count($palette));

                foreach($palette as $id){
                    self::writeBlockState($writer, BlockStateIds::fromId($id));
                }

                $writer->writeBytes($indices ?? "");
            }

            [$biomePalette, $biomeIndices] = self::readExported($storage);
            $writer->writeUnsignedVarInt(count($biomePalette));

            foreach($biomePalette as $biomeId){
                $writer->writeUnsignedShort($biomeId);
            }

            $writer->writeBytes($biomeIndices ?? "");
        }

        $blockEntities = $chunk->getBlockEntities();
        $writer->writeUnsignedVarInt(count($blockEntities));
        $nbt = NbtSerializer::littleEndian();

        foreach($blockEntities as $entity){
            $writer->writeBytes($nbt->write($entity->save()));
        }

        $compressed = zlib_encode($writer->getBuffer(), ZLIB_ENCODING_DEFLATE, 6);

        if($compressed === false){
            throw new UnexpectedValueException("Failed to compress chunk {$chunk->getX()}, {$chunk->getZ()}");
        }

        return self::MAGIC . chr(self::VERSION) . $compressed;
    }

    /**
     * @throws CorruptChunkException
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

            //sub-chunks missing from the file are air
            $empty = "\x01" . self::exported([BlockStateIds::get(new BlockState("minecraft:air"))], null) . self::exported([Chunk::DEFAULT_BIOME], null);
            $storage = [];

            for($i = 0; $i < $subChunkCount; ++$i){
                $y = $reader->readByte();
                $y = $y >= 0x80 ? $y - 0x100 : $y;//signed byte

                if($y < Chunk::MIN_SUBCHUNK || $y > Chunk::MAX_SUBCHUNK){
                    throw new UnexpectedValueException("Sub-chunk Y {$y} is outside the world");
                }

                $layerCount = $reader->readByte();

                if($layerCount < 1 || $layerCount > Chunk::MAX_LAYERS){
                    throw new UnexpectedValueException("A sub-chunk needs 1 to " . Chunk::MAX_LAYERS . " block layers, got {$layerCount}");
                }

                $data = chr($layerCount);

                for($layer = 0; $layer < $layerCount; ++$layer){
                    $palette = [];
                    $paletteSize = self::readPaletteSize($reader);

                    for($entry = 0; $entry < $paletteSize; ++$entry){
                        $palette[] = BlockStateIds::get(self::readBlockState($reader));
                    }

                    $data .= self::exported($palette, self::readIndices($reader, $paletteSize));
                }

                $biomePalette = [];
                $biomePaletteSize = self::readPaletteSize($reader);

                for($entry = 0; $entry < $biomePaletteSize; ++$entry){
                    $biomePalette[] = $reader->readUnsignedShort();
                }

                $storage[$y] = $data . self::exported($biomePalette, self::readIndices($reader, $biomePaletteSize));
            }

            $data = "";

            for($y = Chunk::MIN_SUBCHUNK; $y <= Chunk::MAX_SUBCHUNK; ++$y){
                $data .= $storage[$y] ?? $empty;
            }

            $chunk->importStorage($data);

            if(!$reader->isFinished()){
                $nbt = NbtSerializer::littleEndian();

                for($i = 0, $count = $reader->readUnsignedVarInt(); $i < $count; ++$i){
                    $entity = BlockEntityFactory::load($nbt->readFrom($reader));

                    if($entity !== null && $entity->getX() >> 4 === $x && $entity->getZ() >> 4 === $z && $entity->getY() >= Chunk::MIN_Y && $entity->getY() <= Chunk::MAX_Y){
                        $chunk->addBlockEntity($entity);
                    }
                }
            }

            if(!$reader->isFinished()){
                throw new UnexpectedValueException("Unexpected data after the block entities");
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

    /**
     * A palette and its offsets as the native side exports and imports them.
     *
     * @param list<int> $palette
     */
    private static function exported(array $palette, ?string $indices) : string{
        return pack("V", count($palette)) . pack("V*", ...$palette) . ($indices ?? "");
    }

    /**
     * @return array{list<int>, string|null}
     */
    private static function readExported(BinaryReader $reader) : array{
        $count = $reader->readUnsignedInt();
        $palette = array_values(unpack("V*", $reader->readBytes($count * 4)));

        return [$palette, $count > 1 ? $reader->readBytes(self::STORAGE_SIZE * 2) : null];
    }

    private static function readIndices(BinaryReader $reader, int $paletteSize) : ?string{
        if($paletteSize === 1){
            return null;
        }

        $indices = $reader->readBytes(self::STORAGE_SIZE * 2);

        if(max(unpack("v*", $indices)) >= $paletteSize){
            throw new UnexpectedValueException("Palette index points outside the palette");
        }

        return $indices;
    }

    private static function readPaletteSize(BinaryReader $reader) : int{
        $size = $reader->readUnsignedVarInt();

        if($size < 1 || $size > self::STORAGE_SIZE){
            throw new UnexpectedValueException("Invalid palette size {$size}");
        }

        return $size;
    }
}