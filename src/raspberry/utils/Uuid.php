<?php

declare(strict_types=1);

namespace raspberry\utils;

use InvalidArgumentException;

use function bin2hex;
use function chr;
use function hex2bin;
use function md5;
use function ord;
use function preg_match;
use function random_bytes;
use function str_replace;
use function strlen;
use function substr;
use function vsprintf;
use function str_split;

class Uuid{

    private string $bytes;

    public function __construct(string $bytes){
        if(strlen($bytes) !== 16){
            throw new InvalidArgumentException("A UUID must be 16 bytes, got " . strlen($bytes));
        }
        $this->bytes = $bytes;
    }

    public static function random() : self{
        return self::withVersion(random_bytes(16), 4);
    }

    /**
     * The UUID Bedrock gives an Xbox Live player: a version 3 UUID made from "pocket-auth-1-xuid:" and the XUID.
     */
    public static function fromXuid(string $xuid) : self{
        return self::withVersion(md5("pocket-auth-1-xuid:" . $xuid, true), 3);
    }

    public static function fromString(string $uuid) : self{
        if(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $uuid) !== 1){
            throw new InvalidArgumentException("Invalid UUID: {$uuid}");
        }

        return new self(hex2bin(str_replace("-", "", $uuid)));
    }

    public function getBytes() : string{
        return $this->bytes;
    }

    public function equals(self $other) : bool{
        return $this->bytes === $other->bytes;
    }

    public function toString() : string{
        return vsprintf("%s%s-%s-%s-%s-%s%s%s", str_split(bin2hex($this->bytes), 4));
    }

    public function __toString() : string{
        return $this->toString();
    }

    private static function withVersion(string $bytes, int $version) : self{
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | ($version << 4));
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return new self($bytes);
    }
}