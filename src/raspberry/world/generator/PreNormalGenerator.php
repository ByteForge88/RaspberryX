<?php

declare(strict_types=1);

namespace raspberry\world\generator;

use raspberry\block\BlockStateRegistry;

use raspberry\math\Vector3;

use raspberry\native\Native;

use raspberry\world\Chunk;

use Closure;
use UnexpectedValueException;

use function strlen;
use function unpack;
use function usleep;

class PreNormalGenerator implements AsyncGenerator{

    private const WAIT_MICROSECONDS = 200;

    private int $seed;

    private BlockStateRegistry $registry;

    /** the native generator, made the first time it is needed */
    private int $handle = 0;

    private ?Vector3 $spawn = null;

    public function __construct(int $seed, BlockStateRegistry $registry){
        $this->seed = $seed;
        $this->registry = $registry;
    }

    public function __destruct(){
        if($this->handle !== 0 && Native::isLoaded()){
            Native::get()->rx_pre_normal_free($this->handle);
        }
    }

    public function getName() : string{
        return GeneratorFactory::PRE_NORMAL;
    }

    public function getSpawn() : ?Vector3{
        if($this->spawn === null){
            $data = self::wait(Native::get()->rx_pre_normal_spawn($this->handle()));

            if(strlen($data) !== 12){
                throw new UnexpectedValueException("The native library could not find a spawn");
            }

            $values = unpack("l3", $data);
            $this->spawn = new Vector3($values[1] + 0.5, (float) $values[2], $values[3] + 0.5);
        }

        return $this->spawn;
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
        $id = $ffi->rx_pre_normal_chunk($this->handle(), $chunk->getX(), $chunk->getZ());

        if($id === 0){
            throw new UnexpectedValueException("The native library would not make chunk {$chunk->getX()}, {$chunk->getZ()}");
        }

        Native::await($id, function(string $data) use ($ffi, $chunk, $onDone) : void{
            if(strlen($data) !== 8){
                //the chunk stays empty, nothing waits for it forever
                $onDone();
                throw new UnexpectedValueException("The native library failed to make chunk {$chunk->getX()}, {$chunk->getZ()}");
            }

            $handle = unpack("P", $data)[1];
            $ffi->rx_chunk_copy($handle, $chunk->getHandle());
            $ffi->rx_chunk_free($handle);

            $onDone();
        });
    }

    private function handle() : int{
        if($this->handle === 0){
            [$ids, $count] = NativeBlocks::get($this->registry);
            $this->handle = Native::get()->rx_pre_normal_new($this->seed, $ids, $count);

            if($this->handle === 0){
                throw new UnexpectedValueException("The native library would not make a pre-normal generator");
            }
        }

        return $this->handle;
    }

    /**
     * Waits for work that has to be done before anything else can go on.
     */
    private static function wait(int $id) : string{
        if($id === 0){
            throw new UnexpectedValueException("The native library refused the work");
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

        return $result;
    }
}