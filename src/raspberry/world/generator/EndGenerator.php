<?php

declare(strict_types=1);

namespace raspberry\world\generator;

use raspberry\block\BlockIds;
use raspberry\block\BlockStateIds;
use raspberry\block\BlockStateRegistry;

use raspberry\math\Vector3;

use raspberry\native\Native;

use raspberry\world\Chunk;
use raspberry\world\Dimension;

use Closure;
use UnexpectedValueException;

use function strlen;
use function unpack;
use function usleep;

class EndGenerator implements AsyncGenerator{

    public const int PLATFORM_X = 84;
    public const int PLATFORM_Y = 48;
    public const int PLATFORM_Z = 0;
    public const int PLATFORM_RADIUS = 2;

    private const int WAIT_MICROSECONDS = 200;

    private readonly int $air;
    private readonly int $endStone;
    private readonly int $obsidian;

    public function __construct(
        private readonly int $seed,
        BlockStateRegistry $registry
    ){
        $this->air = BlockStateIds::get($registry->getDefaultState(BlockIds::AIR));
        $this->endStone = BlockStateIds::get($registry->getDefaultState(BlockIds::END_STONE));
        $this->obsidian = BlockStateIds::get($registry->getDefaultState(BlockIds::OBSIDIAN));
    }

    public function getName() : string{
        return GeneratorFactory::THE_END;
    }

    public function getSpawn() : ?Vector3{
        return new Vector3(self::PLATFORM_X + 0.5, self::PLATFORM_Y + 1, self::PLATFORM_Z + 0.5);
    }

    public function generateChunk(Chunk $chunk) : void{
        $done = false;
        $this->generateChunkLater($chunk, function() use (&$done) : void{
            $done = true;
        });

        while(!$done){
            if(Native::poll() === 0){
                usleep(self::WAIT_MICROSECONDS);
            }
        }
    }

    public function generateChunkLater(Chunk $chunk, Closure $onDone) : void{
        $ffi = Native::get();
        $id = $ffi->rx_end_chunk(
            $this->seed, $chunk->getX(), $chunk->getZ(), Dimension::THE_END->getDefaultBiome(),
            $this->air, $this->endStone, $this->obsidian,
            self::PLATFORM_X, self::PLATFORM_Y, self::PLATFORM_Z, self::PLATFORM_RADIUS
        );

        if($id === 0){
            throw new UnexpectedValueException("The native library would not make chunk {$chunk->getX()}, {$chunk->getZ()}");
        }

        Native::await($id, function(string $data) use ($ffi, $chunk, $onDone) : void{
            if(strlen($data) !== 8){
                $onDone();
                throw new UnexpectedValueException("The native library failed to make chunk {$chunk->getX()}, {$chunk->getZ()}");
            }

            $handle = unpack("P", $data)[1];
            $ffi->rx_chunk_copy($handle, $chunk->getHandle());
            $ffi->rx_chunk_free($handle);
            $onDone();
        });
    }
}