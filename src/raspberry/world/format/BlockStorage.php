<?php

declare(strict_types=1);

namespace raspberry\world\format;

use raspberry\block\BlockState;

use UnexpectedValueException;

/**
 * One layer of blocks in a sub-chunk
 *
 * @extends PalettedStorage<BlockState>
 */
class BlockStorage extends PalettedStorage{

    public function __construct(BlockState $fill){
        parent::__construct($fill);
    }

    protected function paletteKey(mixed $value) : string{
        return $value->getKey();
    }

    public function get(int $x, int $y, int $z) : BlockState{
        return $this->getAt(self::index($x, $y, $z));
    }

    public function set(int $x, int $y, int $z, BlockState $block) : void{
        $this->setAt(self::index($x, $y, $z), $block);
    }

    /**
     * @param list<BlockState> $palette
     */
    public static function fromData(array $palette, ?string $indices) : self{
        $storage = new self($palette[0] ?? throw new UnexpectedValueException("Block palette is empty"));
        $storage->restore($palette, $indices);
        return $storage;
    }
}