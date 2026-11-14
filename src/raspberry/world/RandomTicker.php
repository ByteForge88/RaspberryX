<?php

declare(strict_types=1);

namespace raspberry\world;

use raspberry\Server;

use raspberry\block\BlockState;
use raspberry\block\BlockStateIds;
use raspberry\block\growth\Crops;
use raspberry\block\growth\Farmland;
use raspberry\block\growth\Leaves;
use raspberry\block\growth\Melting;
use raspberry\block\growth\Others;
use raspberry\block\growth\Oxidation;
use raspberry\block\growth\Plants;
use raspberry\block\growth\RandomTickBehavior;
use raspberry\block\growth\Saplings;
use raspberry\block\growth\Spreading;

use raspberry\native\Native;

use FFI;

use function array_values;
use function count;
use function max;
use function mt_rand;
use function strlen;
use function unpack;

/**
 * Random ticks: every tick, randomtickspeed blocks of each sub-chunk near a player are picked
 * and the ones that grow, spread, melt or decay get to. The picking runs on the native side.
 */
class RandomTicker{

    /** @var array<int, RandomTickBehavior> by state ID */
    private static array $behaviors = [];

    private static bool $taught = false;

    private World $world;

    private FFI $ffi;

    public function __construct(World $world){
        $this->world = $world;
        $this->ffi = Native::get();

        self::teach();
    }

    public static function register(RandomTickBehavior $behavior) : void{
        self::teach();
        self::add($behavior);
        self::sendStates();
    }

    private static function teach() : void{
        if(self::$taught){
            return;
        }

        self::$taught = true;

        foreach([new Crops(), new Saplings(), new Plants(), new Spreading(), new Leaves(), new Farmland(), new Melting(), new Oxidation(), new Others()] as $behavior){
            self::add($behavior);
        }

        self::sendStates();
    }

    private static function add(RandomTickBehavior $behavior) : void{
        $registry = Server::getInstance()->getBlockStateRegistry();

        foreach($behavior->getBlockNames() as $name){
            if(!$registry->isRegistered($name)){
                continue;
            }

            foreach($registry->getAllStates($name) as $state){
                if($behavior->ticks($state)){
                    self::$behaviors[BlockStateIds::get($state)] = $behavior;
                }
            }
        }
    }

    private static function sendStates() : void{
        $ffi = Native::get();
        $count = count(self::$behaviors);
        $states = $ffi->new("uint32_t[" . max(1, $count) . "]");
        $index = 0;

        foreach(self::$behaviors as $id => $behavior){
            $states[$index++] = $id;
        }

        $ffi->rx_random_tick_blocks($states, $count);
    }

    public function tick() : void{
        $speed = $this->world->getGameRules()->getInt(GameRules::RANDOM_TICK_SPEED);

        if($speed <= 0){
            return;
        }

        $chunks = $this->getTickingChunks();
        $count = count($chunks);

        if($count === 0){
            return;
        }

        $handles = $this->ffi->new("uint64_t[{$count}]");

        foreach($chunks as $index => $chunk){
            $handles[$index] = $chunk->getHandle();
        }

        $out = $this->ffi->new("char*");
        $length = $this->ffi->new("size_t");

        if($this->ffi->rx_random_tick($handles, $count, $speed, (mt_rand() << 31) | mt_rand(), FFI::addr($out), FFI::addr($length)) !== 1){
            return;
        }

        $data = Native::take($out, $length->cdata);

        for($offset = 0, $size = strlen($data); $offset < $size; $offset += 10){
            $hit = unpack("vchunk/Cx/Cz/sy/Vstate", $data, $offset);
            $chunk = $chunks[$hit["chunk"]];
            $behavior = self::$behaviors[$hit["state"]] ?? null;

            if($behavior === null){
                continue;
            }

            $state = $chunk->getBlock($hit["x"], $hit["y"], $hit["z"]);

            if(BlockStateIds::get($state) !== $hit["state"]){
                continue;
            }

            $behavior->onRandomTick($this->world, ($chunk->getX() << 4) + $hit["x"], $hit["y"], ($chunk->getZ() << 4) + $hit["z"], $state);
        }
    }

    /**
     * @return list<Chunk>
     */
    private function getTickingChunks() : array{
        $distance = Server::getInstance()->getTickDistance();
        $chunks = [];

        foreach($this->world->getPlayers() as $player){
            $position = $player->getPosition();
            $centerX = $position->getChunkX();
            $centerZ = $position->getChunkZ();

            for($dx = -$distance; $dx <= $distance; ++$dx){
                for($dz = -$distance; $dz <= $distance; ++$dz){
                    if($dx * $dx + $dz * $dz > $distance * $distance){
                        continue;
                    }

                    $chunk = $this->world->getChunk($centerX + $dx, $centerZ + $dz);

                    if($chunk !== null){
                        $chunks[($centerX + $dx) . ":" . ($centerZ + $dz)] = $chunk;
                    }
                }
            }
        }

        return array_values($chunks);
    }

    public function onBlockChanged(int $x, int $y, int $z, BlockState $old, BlockState $new) : void{
        Leaves::onBlockChanged($this->world, $x, $y, $z, $old, $new);
    }
}