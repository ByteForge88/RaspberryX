<?php

declare(strict_types=1);

namespace raspberry\world\generator;

use InvalidArgumentException;
use raspberry\block\BlockState;
use raspberry\block\BlockStateRegistry;
use raspberry\world\biome\BiomeIds;
use raspberry\world\Chunk;
use function count;
use function explode;
use function preg_match;
use function str_contains;
use function trim;

/**
 * Same layers in every chunk, stacked from the bottom of the world (Y -64) upwards.
 */
class FlatGenerator implements Generator{

    public const DEFAULT_PRESET = "minecraft:bedrock,2*minecraft:dirt,minecraft:grass_block";

    /** @var list<BlockState> one entry per Y level, bottom first */
    private array $column = [];

    private int $biomeId;

    /**
     * @param list<array{BlockState, int}> $layers [block, thickness] pairs, bottom first
     */
    public function __construct(array $layers, int $biomeId = BiomeIds::PLAINS){
        if($layers === []){
            throw new InvalidArgumentException("A flat world needs at least one layer");
        }

        foreach($layers as [$block, $thickness]){
            if($thickness < 1){
                throw new InvalidArgumentException("Layer thickness must be at least 1, got {$thickness} for {$block}");
            }

            for($i = 0; $i < $thickness; ++$i){
                $this->column[] = $block;
            }
        }

        $maxHeight = Chunk::MAX_Y - Chunk::MIN_Y + 1;

        if(count($this->column) > $maxHeight){
            throw new InvalidArgumentException("Flat layers are " . count($this->column) . " blocks tall, the world is only {$maxHeight}");
        }

        $this->biomeId = $biomeId;
    }

    public static function createDefault(BlockStateRegistry $registry) : self{
        return self::fromPreset(self::DEFAULT_PRESET, $registry);
    }

    /**
     * Builds layers from a preset string, bottom layer first:
     *   "minecraft:bedrock,2*minecraft:dirt,minecraft:grass_block"
     * "N*" repeats a layer N times; the "minecraft:" prefix may be left out.
     */
    public static function fromPreset(string $preset, BlockStateRegistry $registry) : self{
        if(trim($preset) === ""){
            $preset = self::DEFAULT_PRESET;
        }

        $layers = [];

        foreach(explode(",", $preset) as $entry){
            $entry = trim($entry);
            $thickness = 1;

            if(preg_match('/^(\d+)\s*\*\s*(.+)$/D', $entry, $matches) === 1){
                $thickness = (int) $matches[1];
                $entry = trim($matches[2]);
            }

            $name = str_contains($entry, ":") ? $entry : "minecraft:" . $entry;

            if(!$registry->isRegistered($name)){
                throw new InvalidArgumentException("Unknown block '{$entry}' in flat preset '{$preset}'");
            }

            $layers[] = [$registry->getDefaultState($name), $thickness];
        }

        return new self($layers);
    }

    public function getName() : string{
        return "flat";
    }

    /**
     * Y of the top layer. Players stand at getSurfaceY() + 1.
     */
    public function getSurfaceY() : int{
        return Chunk::MIN_Y + count($this->column) - 1;
    }

    public function generateChunk(Chunk $chunk) : void{
        if($this->biomeId !== Chunk::DEFAULT_BIOME){
            foreach($chunk->getSubChunks() as $subChunk){
                $subChunk->getBiomeStorage()->fill($this->biomeId);
            }
        }

        for($x = 0; $x < Chunk::WIDTH; ++$x){
            for($z = 0; $z < Chunk::WIDTH; ++$z){
                $y = Chunk::MIN_Y;

                foreach($this->column as $block){
                    $chunk->setBlock($x, $y, $z, $block);
                    ++$y;
                }
            }
        }
    }
}