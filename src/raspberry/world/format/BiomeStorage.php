<?php

declare(strict_types=1);

namespace raspberry\world\format;

use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Biome IDs for a sub-chunk. Bedrock stores one biome per block (16 x 16 x 16).
 *
 * @extends PalettedStorage<int>
 */
class BiomeStorage extends PalettedStorage{

    public function __construct(int $fill){
        self::checkId($fill);

        parent::__construct($fill);
    }

    protected function paletteKey(mixed $value) : int{
        return $value;
    }

    public function get(int $x, int $y, int $z) : int{
        return $this->getAt(self::index($x, $y, $z));
    }

    public function set(int $x, int $y, int $z, int $biomeId) : void{
        self::checkId($biomeId);
        $this->setAt(self::index($x, $y, $z), $biomeId);
    }

    /**
     * @param list<int> $palette
     */
    public static function fromData(array $palette, ?string $indices) : self{
        $storage = new self($palette[0] ?? throw new UnexpectedValueException("Biome palette is empty"));

        foreach($palette as $biomeId){
            self::checkId($biomeId);
        }

        $storage->restore($palette, $indices);

        return $storage;
    }

    private static function checkId(int $biomeId) : void{
        if($biomeId < 0 || $biomeId > 0xffff){
            throw new InvalidArgumentException("Biome ID must be between 0 and 65535, got {$biomeId}");
        }
    }
}