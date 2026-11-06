<?php

declare(strict_types=1);

namespace raspberry\world\generator;

use raspberry\block\BlockIds;
use raspberry\block\BlockState;
use raspberry\block\BlockStateIds;
use raspberry\block\BlockStateRegistry;

use raspberry\math\Vector3;

use raspberry\native\Native;

use raspberry\world\Chunk;

use UnexpectedValueException;

use function strlen;
use function unpack;
use function usleep;

/**
 * The Old world type: a world of 256 x 256 blocks and 128 high, like the first Pocket Edition
 * worlds, with barriers around it. The native library makes the whole world in one go the
 * first time a chunk of it is needed.
 */
class OldGenerator implements Generator{

    /** the world is this many chunks along each side, from chunk 0 */
    public const SIZE_CHUNKS = 16;

    public const HEIGHT = 128;

    private const WAIT_MICROSECONDS = 1000;

    private int $seed;

    private BlockStateRegistry $registry;

    /** @var array<int, int>|null x * SIZE_CHUNKS + z => native chunk not taken yet */
    private ?array $chunks = null;

    private ?Vector3 $spawn = null;

    public function __construct(int $seed, BlockStateRegistry $registry){
        $this->seed = $seed;
        $this->registry = $registry;
    }

    public function __destruct(){
        if($this->chunks !== null && Native::isLoaded()){
            foreach($this->chunks as $handle){
                Native::get()->rx_chunk_free($handle);
            }
        }
    }

    public function getName() : string{
        return GeneratorFactory::OLD;
    }

    public static function isInside(int $chunkX, int $chunkZ) : bool{
        return $chunkX >= 0 && $chunkX < self::SIZE_CHUNKS && $chunkZ >= 0 && $chunkZ < self::SIZE_CHUNKS;
    }

    public function getSpawn() : ?Vector3{
        $this->generate();

        return $this->spawn;
    }

    public function generateChunk(Chunk $chunk) : void{
        $ffi = Native::get();
        [$x, $z] = [$chunk->getX(), $chunk->getZ()];

        if(!self::isInside($x, $z)){
            $ffi->rx_old_border($chunk->getHandle(), $x, $z, BlockStateIds::get(new BlockState(BlockIds::BARRIER)));
            return;
        }

        $key = $x * self::SIZE_CHUNKS + $z;

        //a chunk asked for again (its file was deleted) means making the world once more
        if($this->chunks !== null && !isset($this->chunks[$key])){
            $this->__destruct();
            $this->chunks = null;
        }

        $this->generate();
        $handle = $this->chunks[$key];
        unset($this->chunks[$key]);

        $ffi->rx_chunk_copy($handle, $chunk->getHandle());
        $ffi->rx_chunk_free($handle);
    }

    /**
     * Makes the world on a worker and waits for it. This happens once, when the world is new.
     */
    private function generate() : void{
        if($this->chunks !== null){
            return;
        }

        [$ids, $count] = NativeBlocks::get($this->registry);
        $id = Native::get()->rx_old_generate($this->seed, $ids, $count);

        if($id === 0){
            throw new UnexpectedValueException("The native library would not make the world");
        }

        $result = null;
        Native::await($id, function(string $data) use (&$result) : void{
            $result = $data;
        });

        while($result === null){
            if(Native::poll() === 0){
                usleep(self::WAIT_MICROSECONDS);
            }
        }

        $values = unpack("l3spawn/P*", $result);
        $this->spawn = new Vector3($values["spawn1"] + 0.5, (float) $values["spawn2"], $values["spawn3"] + 0.5);
        $this->chunks = [];

        for($i = 0; $i < (strlen($result) - 12) / 8; ++$i){
            $this->chunks[$i] = $values[$i + 1];
        }
    }
}
