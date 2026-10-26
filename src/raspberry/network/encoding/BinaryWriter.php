<?php

declare(strict_types=1);

namespace raspberry\network\encoding;

use raspberry\utils\Uuid;

use InvalidArgumentException;

final class BinaryWriter{

    private string $buffer = "";

    public function getBuffer() : string{
        return $this->buffer;
    }

    public function reset() : void{
        $this->buffer = "";
    }

    public function writeBytes(string $bytes) : void{
        $this->buffer .= $bytes;
    }

    public function writeByte(int $value) : void{
        self::checkRange($value, 0, 255);
        $this->buffer .= chr($value);
    }

    public function writeBool(bool $value) : void{
        $this->writeByte($value ? 1 : 0);
    }

    public function writeUnsignedShort(int $value, bool $littleEndian = true) : void{
        self::checkRange($value, 0, 0xffff);
        $this->buffer .= pack($littleEndian ? "v" : "n", $value);
    }

    public function writeShort(int $value, bool $littleEndian = true) : void{
        self::checkRange($value, -32768, 32767);
        $this->writeUnsignedShort($value & 0xffff, $littleEndian);
    }

    public function writeUnsignedInt(int $value, bool $littleEndian = true) : void{
        self::checkRange($value, 0, 0xffffffff);
        $this->buffer .= pack($littleEndian ? "V" : "N", $value);
    }

    public function writeInt(int $value, bool $littleEndian = true) : void{
        self::checkRange($value, -2147483648, 2147483647);
        $this->writeUnsignedInt($value & 0xffffffff, $littleEndian);
    }

    public function writeLong(int $value, bool $littleEndian = true) : void{
        $low = $value & 0xffffffff;
        $high = ($value >> 32) & 0xffffffff;

        $this->buffer .= $littleEndian ? pack("V2", $low, $high) : pack("N2", $high, $low);
    }

    public function writeFloat(float $value, bool $littleEndian = true) : void{
        $this->buffer .= pack($littleEndian ? "g" : "G", $value);
    }

    public function writeDouble(float $value, bool $littleEndian = true) : void{
        $this->buffer .= pack($littleEndian ? "e" : "E", $value);
    }

    public function writeUnsignedVarInt(int $value) : void{
        self::checkRange($value, 0, 0xffffffff);

        while($value >= 0x80){
            $this->writeByte(($value & 0x7f) | 0x80);
            $value >>= 7;
        }

        $this->writeByte($value);
    }

    public function writeVarInt(int $value) : void{
        self::checkRange($value, -2147483648, 2147483647);

        $encoded = (($value << 1) ^ ($value >> 31)) & 0xffffffff;
        $this->writeUnsignedVarInt($encoded);
    }

    /**
     * Unsigned 64-bit VarInt. PHP has no unsigned 64-bit type, so negative values
     * are written as their two's complement bit pattern (up to 10 bytes).
     */
    public function writeUnsignedVarLong(int $value) : void{
        for($i = 0; $i < 10; ++$i){
            if(($value >> 7) === 0 && $value >= 0){
                $this->buffer .= chr($value);
                return;
            }

            $this->buffer .= chr(($value & 0x7f) | 0x80);
            $value = ($value >> 7) & 0x01ffffffffffffff;//logical shift
        }
    }

    /**
     * Signed 64-bit VarInt with ZigZag encoding.
     */
    public function writeVarLong(int $value) : void{
        $this->writeUnsignedVarLong(($value << 1) ^ ($value >> 63));
    }

    /**
     * A UUID as Bedrock sends it: both 8-byte halves in little-endian order.
     */
    public function writeUuid(Uuid $uuid) : void{
        $bytes = $uuid->getBytes();
        $this->buffer .= strrev(substr($bytes, 0, 8)) . strrev(substr($bytes, 8, 8));
    }

    public function writeString(string $value, int $maxBytes = 1048576) : void{
        if($maxBytes < 0){
            throw new InvalidArgumentException("Maximum string length cannot be negative");
        }

        $length = strlen($value);

        if($length > $maxBytes){
            throw new InvalidArgumentException("String exceeds the maximum length of {$maxBytes} bytes");
        }

        $this->writeUnsignedVarInt($length);
        $this->writeBytes($value);
    }

    private static function checkRange(int $value, int $minimum, int $maximum) : void{
        if($value < $minimum || $value > $maximum){
            throw new InvalidArgumentException("Value {$value} is outside {$minimum} to {$maximum}");
        }
    }
}