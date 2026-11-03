<?php

declare(strict_types=1);

namespace raspberry\nbt;

use raspberry\nbt\tag\ByteArrayTag;
use raspberry\nbt\tag\ByteTag;
use raspberry\nbt\tag\CompoundTag;
use raspberry\nbt\tag\DoubleTag;
use raspberry\nbt\tag\FloatTag;
use raspberry\nbt\tag\IntArrayTag;
use raspberry\nbt\tag\IntTag;
use raspberry\nbt\tag\ListTag;
use raspberry\nbt\tag\LongArrayTag;
use raspberry\nbt\tag\LongTag;
use raspberry\nbt\tag\ShortTag;
use raspberry\nbt\tag\StringTag;
use raspberry\nbt\tag\Tag;

use raspberry\network\encoding\BinaryReader;
use raspberry\network\encoding\BinaryWriter;

use Throwable;

use function count;
use function strlen;

class NbtSerializer{

    /** nesting deeper than this is refused, so a broken file cannot exhaust memory */
    private const MAX_DEPTH = 512;

    private bool $littleEndian;

    public function __construct(bool $littleEndian){
        $this->littleEndian = $littleEndian;
    }

    public static function littleEndian() : self{
        return new self(true);
    }

    public static function bigEndian() : self{
        return new self(false);
    }

    public function write(CompoundTag $root, string $rootName = "") : string{
        $writer = new BinaryWriter();
        $writer->writeByte(Tag::TAG_COMPOUND);
        $this->writeString($writer, $rootName);
        $this->writePayload($writer, $root, 0);

        return $writer->getBuffer();
    }

    /**
     * @param string|null $rootName set to the name of the root tag
     *
     * @throws NbtException when the data is not a valid NBT compound
     */
    public function read(string $data, ?string &$rootName = null) : CompoundTag{
        $reader = new BinaryReader($data);
        $root = $this->readFrom($reader, $rootName);

        if(!$reader->isFinished()){
            throw new NbtException("Unexpected data after the root tag");
        }

        return $root;
    }

    /**
     * Reads one root compound from $reader, leaving it just after the compound. For NBT that is
     * followed by other data, like the NBT inside an item in a packet.
     *
     * @throws NbtException
     */
    public function readFrom(BinaryReader $reader, ?string &$rootName = null) : CompoundTag{
        try{
            $type = $reader->readByte();

            if($type !== Tag::TAG_COMPOUND){
                throw new NbtException("The root tag must be a compound, got " . Tag::getTypeName($type));
            }

            $rootName = $this->readString($reader);
            $root = $this->readPayload($reader, Tag::TAG_COMPOUND, 0);
        }catch(NbtException $exception){
            throw $exception;
        }catch(Throwable $exception){
            throw new NbtException("Invalid NBT: " . $exception->getMessage(), 0, $exception);
        }

        /** @var CompoundTag $root */
        return $root;
    }

    private function writePayload(BinaryWriter $writer, Tag $tag, int $depth) : void{
        if($depth > self::MAX_DEPTH){
            throw new NbtException("NBT is nested too deeply");
        }

        $value = $tag->getValue();

        match(true){
            $tag instanceof ByteTag => $writer->writeByte($value & 0xff),
            $tag instanceof ShortTag => $writer->writeShort($value, $this->littleEndian),
            $tag instanceof IntTag => $writer->writeInt($value, $this->littleEndian),
            $tag instanceof LongTag => $writer->writeLong($value, $this->littleEndian),
            $tag instanceof FloatTag => $writer->writeFloat($value, $this->littleEndian),
            $tag instanceof DoubleTag => $writer->writeDouble($value, $this->littleEndian),
            $tag instanceof StringTag => $this->writeString($writer, $value),
            $tag instanceof ByteArrayTag => $this->writeByteArray($writer, $value),
            $tag instanceof IntArrayTag => $this->writeNumberArray($writer, $value, false),
            $tag instanceof LongArrayTag => $this->writeNumberArray($writer, $value, true),
            $tag instanceof ListTag => $this->writeList($writer, $tag, $depth),
            $tag instanceof CompoundTag => $this->writeCompound($writer, $tag, $depth),
            default => throw new NbtException("Cannot write tag " . $tag::class)
        };
    }

    private function writeCompound(BinaryWriter $writer, CompoundTag $compound, int $depth) : void{
        foreach($compound->getValue() as $name => $tag){
            $writer->writeByte($tag->getType());
            $this->writeString($writer, (string) $name);
            $this->writePayload($writer, $tag, $depth + 1);
        }

        $writer->writeByte(Tag::TAG_END);
    }

    private function writeList(BinaryWriter $writer, ListTag $list, int $depth) : void{
        $writer->writeByte($list->getTagType());
        $writer->writeInt($list->count(), $this->littleEndian);

        foreach($list->getValue() as $tag){
            $this->writePayload($writer, $tag, $depth + 1);
        }
    }

    private function writeString(BinaryWriter $writer, string $value) : void{
        if(strlen($value) > StringTag::MAX_LENGTH){
            throw new NbtException("String is longer than " . StringTag::MAX_LENGTH . " bytes");
        }

        $writer->writeUnsignedShort(strlen($value), $this->littleEndian);
        $writer->writeBytes($value);
    }

    private function writeByteArray(BinaryWriter $writer, string $value) : void{
        $writer->writeInt(strlen($value), $this->littleEndian);
        $writer->writeBytes($value);
    }

    /**
     * @param list<int> $values
     */
    private function writeNumberArray(BinaryWriter $writer, array $values, bool $long) : void{
        $writer->writeInt(count($values), $this->littleEndian);

        foreach($values as $value){
            $long ? $writer->writeLong($value, $this->littleEndian) : $writer->writeInt($value, $this->littleEndian);
        }
    }

    private function readPayload(BinaryReader $reader, int $type, int $depth) : Tag{
        if($depth > self::MAX_DEPTH){
            throw new NbtException("NBT is nested too deeply");
        }

        return match($type){
            Tag::TAG_BYTE => new ByteTag(($byte = $reader->readByte()) > 127 ? $byte - 256 : $byte),
            Tag::TAG_SHORT => new ShortTag($reader->readShort($this->littleEndian)),
            Tag::TAG_INT => new IntTag($reader->readInt($this->littleEndian)),
            Tag::TAG_LONG => new LongTag($reader->readLong($this->littleEndian)),
            Tag::TAG_FLOAT => new FloatTag($reader->readFloat($this->littleEndian)),
            Tag::TAG_DOUBLE => new DoubleTag($reader->readDouble($this->littleEndian)),
            Tag::TAG_STRING => new StringTag($this->readString($reader)),
            Tag::TAG_BYTE_ARRAY => new ByteArrayTag($reader->readBytes($this->readLength($reader))),
            Tag::TAG_INT_ARRAY => new IntArrayTag($this->readNumberArray($reader, false)),
            Tag::TAG_LONG_ARRAY => new LongArrayTag($this->readNumberArray($reader, true)),
            Tag::TAG_LIST => $this->readList($reader, $depth),
            Tag::TAG_COMPOUND => $this->readCompound($reader, $depth),
            default => throw new NbtException("Unknown tag type {$type}")
        };
    }

    private function readCompound(BinaryReader $reader, int $depth) : CompoundTag{
        $compound = new CompoundTag();

        while(($type = $reader->readByte()) !== Tag::TAG_END){
            $name = $this->readString($reader);
            $compound->setTag($name, $this->readPayload($reader, $type, $depth + 1));
        }

        return $compound;
    }

    private function readList(BinaryReader $reader, int $depth) : ListTag{
        $type = $reader->readByte();
        $count = $this->readLength($reader);
        $list = new ListTag([], $count === 0 ? Tag::TAG_END : $type);

        for($i = 0; $i < $count; ++$i){
            $list->push($this->readPayload($reader, $type, $depth + 1));
        }

        return $list;
    }

    private function readString(BinaryReader $reader) : string{
        return $reader->readBytes($reader->readUnsignedShort($this->littleEndian));
    }

    /**
     * @return list<int>
     */
    private function readNumberArray(BinaryReader $reader, bool $long) : array{
        $values = [];

        for($i = 0, $count = $this->readLength($reader); $i < $count; ++$i){
            $values[] = $long ? $reader->readLong($this->littleEndian) : $reader->readInt($this->littleEndian);
        }

        return $values;
    }

    private function readLength(BinaryReader $reader) : int{
        $length = $reader->readInt($this->littleEndian);

        if($length < 0 || $length > $reader->remaining()){
            throw new NbtException("Invalid length {$length}");
        }

        return $length;
    }
}