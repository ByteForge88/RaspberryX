<?php

declare(strict_types=1);

namespace raspberry\world\format;

use raspberry\block\BlockIds;
use raspberry\block\BlockState;

use InvalidArgumentException;

use function count;
use function array_values;

/**
 * A 16 x 16 x 16 section of a chunk.
 *
 * Bedrock sub-chunks can have several block layers: layer 0 holds the normal block,
 * layer 1 holds water inside waterlogged blocks. Layers are created when first written.
 */
class SubChunk{

    public const EDGE = 16;

    public const MAX_LAYERS = 2;

    /** @var list<BlockStorage> */
    private array $layers;

    private BiomeStorage $biomes;

    public function __construct(int $biomeId){
        $this->layers = [new BlockStorage(self::air())];
        $this->biomes = new BiomeStorage($biomeId);
    }

    /**
     * Rebuilds a sub-chunk from saved storages.
     *
     * @param list<BlockStorage> $layers
     */
    public static function fromStorages(array $layers, BiomeStorage $biomes) : self{
        if($layers === [] || count($layers) > self::MAX_LAYERS){
            throw new InvalidArgumentException("A sub-chunk needs 1 to " . self::MAX_LAYERS . " block layers, got " . count($layers));
        }

        $subChunk = new self(0);
        $subChunk->layers = array_values($layers);
        $subChunk->biomes = $biomes;
        return $subChunk;
    }

    public function getBlock(int $x, int $y, int $z, int $layer = 0) : BlockState{
        self::checkLayer($layer);

        return isset($this->layers[$layer]) ? $this->layers[$layer]->get($x, $y, $z) : self::air();
    }

    public function setBlock(int $x, int $y, int $z, BlockState $block, int $layer = 0) : void{
        self::checkLayer($layer);

        if(!isset($this->layers[$layer])){
            if($block->getName() === BlockIds::AIR){
                PalettedStorage::index($x, $y, $z); //still validate coordinates
                return;
            }

            for($i = count($this->layers); $i <= $layer; ++$i){
                $this->layers[$i] = new BlockStorage(self::air());
            }
        }

        $this->layers[$layer]->set($x, $y, $z, $block);
    }

    /**
     * @return list<BlockStorage>
     */
    public function getBlockLayers() : array{
        return $this->layers;
    }

    public function getBiome(int $x, int $y, int $z) : int{
        return $this->biomes->get($x, $y, $z);
    }

    public function setBiome(int $x, int $y, int $z, int $biomeId) : void{
        $this->biomes->set($x, $y, $z, $biomeId);
    }

    public function getBiomeStorage() : BiomeStorage{
        return $this->biomes;
    }

    /**
     * Removes unused palette entries and empty upper layers.
     */
    public function collectGarbage() : void{
        foreach($this->layers as $layer){
            $layer->collectGarbage();
        }

        while(count($this->layers) > 1 && self::isAirOnly($this->layers[count($this->layers) - 1])){
            unset($this->layers[count($this->layers) - 1]);
        }

        $this->biomes->collectGarbage();
    }

    /**
     * True when the sub-chunk contains only air (biomes are ignored).
     */
    public function isEmpty() : bool{
        $this->collectGarbage();

        return count($this->layers) === 1 && self::isAirOnly($this->layers[0]);
    }

    private static function isAirOnly(BlockStorage $storage) : bool{
        return $storage->isUniform() && $storage->getPalette()[0]->getName() === BlockIds::AIR;
    }

    private static function checkLayer(int $layer) : void{
        if($layer < 0 || $layer >= self::MAX_LAYERS){
            throw new InvalidArgumentException("Block layer must be between 0 and " . (self::MAX_LAYERS - 1) . ", got {$layer}");
        }
    }

    private static function air() : BlockState{
        static $air = null;
        return $air ??= new BlockState(BlockIds::AIR);
    }
}