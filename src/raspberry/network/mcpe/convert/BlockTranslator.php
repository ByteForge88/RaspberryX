<?php

declare(strict_types=1);

namespace raspberry\network\mcpe\convert;

use raspberry\block\BlockState;

use function chr;
use function hash;
use function hexdec;
use function is_bool;
use function is_int;
use function pack;
use function strlen;

/**
 * Turns block states into the IDs used in network chunk data.
 *
 * RaspberryX enables "block network ID hashes" in StartGame, so a block's network ID is the
 * FNV-1a 32-bit hash of its state written as little-endian NBT:
 *   {name: "minecraft:...", states: {property: value, ...}}   (properties sorted by name)
 * The client computes the same hash, so no palette has to be shared with it.
 */
class BlockTranslator{

    /** @var array<string, int> block state key => network ID */
    private array $cache = [];

    /**
     * The network ID as a signed 32-bit integer, ready to be written as a VarInt.
     */
    public function getNetworkId(BlockState $state) : int{
        $key = $state->getKey();

        if(isset($this->cache[$key])){
            return $this->cache[$key];
        }

        $hash = self::hash($state);

        return $this->cache[$key] = $hash >= 0x80000000 ? $hash - 0x100000000 : $hash;
    }

    /**
     * The unsigned 32-bit FNV-1a hash of the block state.
     */
    public static function hash(BlockState $state) : int{
        $data = "\x0a" . self::string("") . "\x08" . self::string("name") . self::string($state->getName()) . "\x0a" . self::string("states");

        foreach($state->getProperties() as $property => $value){
            if(is_bool($value)){
                $data .= "\x01" . self::string($property) . chr($value ? 1 : 0);
            }elseif(is_int($value)){
                $data .= "\x03" . self::string($property) . pack("V", $value & 0xffffffff);
            }else{
                $data .= "\x08" . self::string($property) . self::string($value);
            }
        }

        $data .= "\x00\x00";

        return (int) hexdec(hash("fnv1a32", $data));
    }

    private static function string(string $value) : string{
        return pack("v", strlen($value)) . $value;
    }
}