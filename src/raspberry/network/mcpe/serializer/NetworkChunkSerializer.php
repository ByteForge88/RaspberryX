<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\serializer;

use raspberry\network\encoding\BinaryWriter;
use raspberry\network\mcpe\convert\BlockTranslator;
use raspberry\world\Chunk;
use raspberry\world\format\BiomeStorage;
use raspberry\world\format\PalettedStorage;

use LogicException;

use function array_map;
use function count;
use function intdiv;
use function pack;

/**
 * Encodes a chunk for LevelChunkPacket (the non-cached, full-chunk form).
 *
 * Payload: every sub-chunk, then one biome storage per sub-chunk, then a 0 byte (no border blocks).
 * Sub-chunk: byte version (9), byte layer count, byte sub-chunk Y, then each layer as a paletted storage.
 * Paletted storage: byte (bitsPerBlock << 1 | 1), uint32 words with the packed palette indices,
 * then the palette as VarInts (count omitted when bitsPerBlock is 0).
 *
 * This is the network format only. Saving chunks to disk uses world\format\io\ChunkSerializer.
 */
class NetworkChunkSerializer{

    private const SUBCHUNK_VERSION = 9;

    /** bits per block Bedrock accepts, smallest first */
    private const BIT_SIZES = [0, 1, 2, 3, 4, 5, 6, 8, 16];

    /** written instead of a biome storage that is identical to the previous one */
    private const BIOME_SAME_AS_PREVIOUS = 0xff;

    public static function getSubChunkCount() : int{
        return Chunk::MAX_SUBCHUNK - Chunk::MIN_SUBCHUNK + 1;
    }

    public static function serialize(Chunk $chunk, BlockTranslator $translator) : string{
        $chunk->collectGarbage();

        $writer = new BinaryWriter();

        foreach($chunk->getSubChunks() as $y => $subChunk){
            $layers = $subChunk->getBlockLayers();

            $writer->writeByte(self::SUBCHUNK_VERSION);
            $writer->writeByte(count($layers));
            $writer->writeByte($y & 0xff);

            foreach($layers as $layer){
                self::writeStorage($writer, $layer, array_map($translator->getNetworkId(...), $layer->getPalette()));
            }
        }

        $previous = null;

        foreach($chunk->getSubChunks() as $subChunk){
            $biomes = $subChunk->getBiomeStorage();

            if($previous !== null && self::sameUniformBiome($previous, $biomes)){
                $writer->writeByte(self::BIOME_SAME_AS_PREVIOUS);
            }else{
                self::writeStorage($writer, $biomes, $biomes->getPalette());
            }

            $previous = $biomes;
        }

        $writer->writeByte(0); //border block count

        return $writer->getBuffer();
    }

    /**
     * @param list<int> $palette network values of the storage's palette, same order
     */
    private static function writeStorage(BinaryWriter $writer, PalettedStorage $storage, array $palette) : void{
        $bits = self::bitsFor(count($palette));

        $writer->writeByte(($bits << 1) | 1);

        if($bits === 0){
            $writer->writeVarInt($palette[0]);
            return;
        }

        $blocksPerWord = intdiv(32, $bits);
        $wordCount = intdiv(PalettedStorage::SIZE + $blocksPerWord - 1, $blocksPerWord);
        $words = [];

        foreach($storage->getPaletteIndices() as $position => $index){
            $word = intdiv($position, $blocksPerWord);
            $words[$word] = ($words[$word] ?? 0) | ($index << (($position % $blocksPerWord) * $bits));
        }

        for($i = 0; $i < $wordCount; ++$i){
            $writer->writeBytes(pack("V", $words[$i] ?? 0));
        }

        $writer->writeVarInt(count($palette));

        foreach($palette as $value){
            $writer->writeVarInt($value);
        }
    }

    private static function bitsFor(int $paletteSize) : int{
        foreach(self::BIT_SIZES as $bits){
            if($paletteSize <= (1 << $bits)){
                return $bits;
            }
        }

        throw new LogicException("Palette of {$paletteSize} entries is too large");
    }

    private static function sameUniformBiome(BiomeStorage $a, BiomeStorage $b) : bool{
        return $a->isUniform() && $b->isUniform() && $a->getPalette()[0] === $b->getPalette()[0];
    }
}