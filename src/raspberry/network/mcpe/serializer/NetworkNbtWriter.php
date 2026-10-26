<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\serializer;

use raspberry\network\encoding\BinaryWriter;

use LogicException;

/**
 * Writes NBT in Bedrock's network little-endian format: lengths and ints are VarInts,
 * everything else is little-endian. Only the tags the protocol currently needs are supported.
 *
 *   $nbt = new NetworkNbtWriter();
 *   $nbt->startCompound();
 *   $nbt->writeString("name", "minecraft:stone");
 *   $nbt->endCompound();
 *   $bytes = $nbt->getBuffer();
 *
 * TODO: Rewrite the NBT
 */
class NetworkNbtWriter{

    public const TAG_END = 0;
    public const TAG_BYTE = 1;
    public const TAG_SHORT = 2;
    public const TAG_INT = 3;
    public const TAG_LONG = 4;
    public const TAG_FLOAT = 5;
    public const TAG_DOUBLE = 6;
    public const TAG_STRING = 8;
    public const TAG_LIST = 9;
    public const TAG_COMPOUND = 10;

    private BinaryWriter $writer;

    private int $depth = 0;

    public function __construct(){
        $this->writer = new BinaryWriter();
    }

    /**
     * The 3 bytes of an empty root compound, the most common NBT value in packets.
     */
    public static function emptyCompound() : string{
        $nbt = new self();
        $nbt->startCompound();
        $nbt->endCompound();
        return $nbt->getBuffer();
    }

    /**
     * Opens a compound. The root compound normally has an empty name.
     */
    public function startCompound(string $name = "") : void{
        $this->tagHeader(self::TAG_COMPOUND, $name);
        ++$this->depth;
    }

    public function endCompound() : void{
        if($this->depth === 0){
            throw new LogicException("No compound is open");
        }

        $this->writer->writeByte(self::TAG_END);
        --$this->depth;
    }

    public function writeByte(string $name, int $value) : void{
        $this->tagHeader(self::TAG_BYTE, $name);
        $this->writer->writeByte($value & 0xff);
    }

    public function writeInt(string $name, int $value) : void{
        $this->tagHeader(self::TAG_INT, $name);
        $this->writer->writeVarInt($value);
    }

    public function writeLong(string $name, int $value) : void{
        $this->tagHeader(self::TAG_LONG, $name);
        $this->writer->writeVarLong($value);
    }

    public function writeString(string $name, string $value) : void{
        $this->tagHeader(self::TAG_STRING, $name);
        $this->writer->writeString($value, 32767);
    }

    /**
     * An empty list whose elements would be of $elementType (e.g. TAG_COMPOUND).
     */
    public function writeEmptyList(string $name, int $elementType) : void{
        $this->tagHeader(self::TAG_LIST, $name);
        $this->writer->writeByte($elementType);
        $this->writer->writeVarInt(0);
    }

    public function getBuffer() : string{
        if($this->depth !== 0){
            throw new LogicException("{$this->depth} compound(s) are still open");
        }

        return $this->writer->getBuffer();
    }

    private function tagHeader(int $type, string $name) : void{
        $this->writer->writeByte($type);
        $this->writer->writeString($name, 32767);
    }
}