<?php

declare(strict_types=1);

namespace raspberry\world\format;

use UnexpectedValueException;
use InvalidArgumentException;

use function array_fill;
use function array_values;
use function chr;
use function count;
use function ord;
use function pack;
use function str_repeat;
use function unpack;
use function max;
use function strlen;

/**
 * 16 x 16 x 16 values stored as a palette plus one 16-bit palette index per position.
 *
 * Indices are packed into a binary string (8 KiB) instead of a PHP array (~130 KiB).
 * When every position holds the same value, no index string exists at all.
 *
 * Positions use Bedrock's XZY order: index = (x << 8) | (z << 4) | y
 *
 * @template T
 */
abstract class PalettedStorage{

    public const SIZE = 4096;

    /** @var list<T> */
    private array $palette;

    /** @var array<string|int, int> palette key => palette offset */
    private array $lookup;

    private ?string $indices = null;

    /**
     * @param T $fill value every position starts with
     */
    public function __construct(mixed $fill){
        $this->palette = [$fill];
        $this->lookup = [$this->paletteKey($fill) => 0];
    }

    /**
     * A unique array key for a value, used to find it in the palette.
     *
     * @param T $value
     */
    abstract protected function paletteKey(mixed $value) : string|int;

    public static function index(int $x, int $y, int $z) : int{
        if((($x | $y | $z) & ~0x0f) !== 0){
            throw new InvalidArgumentException("Sub-chunk coordinates must be between 0 and 15, got {$x}, {$y}, {$z}");
        }

        return ($x << 8) | ($z << 4) | $y;
    }

    /**
     * @return T
     */
    protected function getAt(int $index) : mixed{
        if($this->indices === null){
            return $this->palette[0];
        }

        $offset = $index << 1;

        return $this->palette[ord($this->indices[$offset]) | (ord($this->indices[$offset + 1]) << 8)];
    }

    /**
     * @param T $value
     */
    protected function setAt(int $index, mixed $value) : void{
        $key = $this->paletteKey($value);
        $id = $this->lookup[$key] ?? null;

        if($id === null){
            if(count($this->palette) >= self::SIZE){
                $this->collectGarbage();
            }

            $id = count($this->palette);
            $this->palette[] = $value;
            $this->lookup[$key] = $id;
        }

        if($this->indices === null){
            if($id === 0){
                return;
            }

            $this->indices = str_repeat("\x00", self::SIZE * 2);
        }

        $offset = $index << 1;

        $this->indices[$offset] = chr($id & 0xff);
        $this->indices[$offset + 1] = chr($id >> 8);
    }

    /**
     * Fills every position with one value and frees the index data.
     *
     * @param T $value
     */
    public function fill(mixed $value) : void{
        $this->palette = [$value];
        $this->lookup = [$this->paletteKey($value) => 0];
        $this->indices = null;
    }

    /**
     * Removes palette entries that no position uses any more, e.g. after blocks were replaced.
     * Call this before serializing or checking whether the storage is uniform.
     */
    public function collectGarbage() : void{
        if($this->indices === null){
            $this->fill($this->palette[0]);
            return;
        }

        /** @var array<int, int> $old 1-based, as returned by unpack() */
        $old = unpack("v*", $this->indices);

        $remap = [];
        $palette = [];
        $lookup = [];

        foreach($old as $position => $id){
            if(!isset($remap[$id])){
                $remap[$id] = count($palette);
                $palette[] = $this->palette[$id];
                $lookup[$this->paletteKey($this->palette[$id])] = $remap[$id];
            }

            $old[$position] = $remap[$id];
        }

        $this->palette = $palette;
        $this->lookup = $lookup;
        $this->indices = count($palette) === 1 ? null : pack("v*", ...$old);
    }

    /**
     * True when every position is known to hold the same value.
     * Run collectGarbage() first for an exact answer.
     */
    public function isUniform() : bool{
        return $this->indices === null;
    }

    /**
     * @return list<T>
     */
    public function getPalette() : array{
        return $this->palette;
    }

    /**
     * Palette offsets as 4096 little-endian uint16 values (8192 bytes) in XZY order,
     * or null when every position uses palette entry 0.
     */
    public function getRawIndices() : ?string{
        return $this->indices;
    }

    /**
     * Replaces the whole content, e.g. with data read from disk.
     *
     * @param list<T>     $palette
     * @param string|null $indices 8192 bytes as returned by getRawIndices(), or null for a uniform storage
     */
    protected function restore(array $palette, ?string $indices) : void{
        $count = count($palette);

        if($count < 1 || $count > self::SIZE){
            throw new UnexpectedValueException("Palette must have between 1 and " . self::SIZE . " entries, got {$count}");
        }

        $lookup = [];

        foreach(array_values($palette) as $offset => $value){
            $lookup[$this->paletteKey($value)] = $offset;
        }

        if(count($lookup) !== $count){
            throw new UnexpectedValueException("Palette contains duplicate entries");
        }

        if($indices !== null){
            if(strlen($indices) !== self::SIZE * 2){
                throw new UnexpectedValueException("Palette indices must be " . (self::SIZE * 2) . " bytes, got " . strlen($indices));
            }

            if(max(unpack("v*", $indices)) >= $count){
                throw new UnexpectedValueException("Palette index points outside the palette");
            }
        }

        $this->palette = array_values($palette);
        $this->lookup = $lookup;
        $this->indices = $count === 1 ? null : $indices ?? throw new UnexpectedValueException("A palette with several entries needs indices");
    }

    /**
     * Palette offsets for all 4096 positions in XZY order (for serializers).
     *
     * @return list<int>
     */
    public function getPaletteIndices() : array{
        if($this->indices === null){
            return array_fill(0, self::SIZE, 0);
        }

        return array_values(unpack("v*", $this->indices));
    }
}