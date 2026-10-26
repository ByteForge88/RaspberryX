<?php

declare(strict_types=1);

namespace raspberry\network\encoding;

use raspberry\utils\Uuid;

use InvalidArgumentException;
use UnexpectedValueException;

use function ord;
use function strlen;
use function unpack;

final class BinaryReader{

    private int $offset = 0;

    private int $length;

    public function __construct(private string $buffer){
        $this->length = strlen($buffer);
    }

    public function getOffset() : int{
        return $this->offset;
    }

    public function remaining() : int{
        return $this->length - $this->offset;
    }

    public function isFinished() : bool{
        return $this->offset === $this->length;
    }

    public function readBytes(int $length) : string{
        if($length < 0){
            throw new InvalidArgumentException("Read length cannot be negative");
        }

        if($length > $this->remaining()){
            throw new UnexpectedValueException("Truncated input at offset {$this->offset}: " . "requested {$length} bytes, " . "{$this->remaining()} available");
        }

        $bytes = substr($this->buffer, $this->offset, $length);
        $this->offset += $length;

        return $bytes;
    }

    public function readByte() : int{
        return ord($this->readBytes(1));
    }

    public function readBool() : bool{
        $value = $this->readByte();

        if($value !== 0 && $value !== 1){
            throw new UnexpectedValueException("Invalid boolean byte: {$value}");
        }

        return $value === 1;
    }

    public function readUnsignedShort(bool $littleEndian = true) : int{
        return unpack($littleEndian ? "vvalue" : "nvalue", $this->readBytes(2))["value"];
    }

    public function readShort(bool $littleEndian = true) : int{
        $value = $this->readUnsignedShort($littleEndian);

        return $value >= 0x8000 ? $value - 0x10000 : $value;
    }

    public function readUnsignedInt(bool $littleEndian = true) : int{
        return unpack($littleEndian ? "Vvalue" : "Nvalue", $this->readBytes(4))["value"];
    }

    public function readInt(bool $littleEndian = true) : int{
        $value = $this->readUnsignedInt($littleEndian);

        return $value >= 0x80000000 ? $value - 0x100000000 : $value;
    }

    public function readLong(bool $littleEndian = true) : int{
        $parts = unpack($littleEndian ? "Vlow/Vhigh" : "Nhigh/Nlow", $this->readBytes(8));

        return ($parts["high"] << 32) | $parts["low"];
    }

    public function readFloat(bool $littleEndian = true) : float{
        return unpack($littleEndian ? "gvalue" : "Gvalue", $this->readBytes(4))["value"];
    }

    public function readDouble(bool $littleEndian = true) : float{
        return unpack($littleEndian ? "evalue" : "Evalue", $this->readBytes(8))["value"];
    }

    public function readUnsignedVarInt() : int{
        $value = 0;

        for($index = 0; $index < 5; ++$index){
            $byte = $this->readByte();

            if($index === 4 && ($byte & 0xf0) !== 0){
                throw new UnexpectedValueException("Unsigned VarInt exceeds 32 bits");
            }

            $value |= ($byte & 0x7f) << ($index * 7);

            if(($byte & 0x80) === 0){
                return $value;
            }
        }

        throw new UnexpectedValueException("Invalid unsigned VarInt");
    }

    public function readVarInt() : int{
        $value = $this->readUnsignedVarInt();

        return ($value >> 1) ^ -($value & 1);
    }

    /**
     * Unsigned 64-bit VarInt. Values above PHP_INT_MAX come back as negative numbers
     * (the same bit pattern), matching writeUnsignedVarLong().
     */
    public function readUnsignedVarLong() : int{
        $value = 0;

        for($index = 0; $index < 10; ++$index){
            $byte = $this->readByte();

            if($index === 9 && ($byte & 0xfe) !== 0){
                throw new UnexpectedValueException("Unsigned VarLong exceeds 64 bits");
            }

            $value |= ($byte & 0x7f) << ($index * 7);

            if(($byte & 0x80) === 0){
                return $value;
            }
        }

        throw new UnexpectedValueException("Invalid unsigned VarLong");
    }

    /**
     * Signed 64-bit VarInt with ZigZag encoding.
     */
    public function readVarLong() : int{
        $value = $this->readUnsignedVarLong();
        return (($value >> 1) & PHP_INT_MAX) ^ -($value & 1);
    }

    public function readUuid() : Uuid{
        $bytes = $this->readBytes(16);
        return new Uuid(strrev(substr($bytes, 0, 8)) . strrev(substr($bytes, 8, 8)));
    }

    public function readString(int $maxBytes = 1048576) : string{
        if($maxBytes < 0){
            throw new InvalidArgumentException("Maximum string length cannot be negative");
        }

        $length = $this->readUnsignedVarInt();

        if($length > $maxBytes){
            throw new UnexpectedValueException("String exceeds the maximum length of {$maxBytes} bytes");
        }

        return $this->readBytes($length);
    }
}