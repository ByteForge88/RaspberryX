<?php

declare(strict_types=1);

namespace raspberry\world\liquid;

use raspberry\Server;

use raspberry\block\BlockBreakInfo;
use raspberry\block\BlockCollision;
use raspberry\block\BlockIds;
use raspberry\block\BlockState;
use raspberry\block\BlockStateIds;
use raspberry\block\BlockStateRegistry;

use raspberry\item\Item;

use raspberry\math\Vector3;

use raspberry\native\Native;

use raspberry\network\mcpe\protocol\LevelEventPacket;
use raspberry\network\mcpe\protocol\types\LevelEvent;

use raspberry\world\Chunk;
use raspberry\world\World;

use FFI;

use function count;
use function mt_rand;
use function preg_match;
use function str_ends_with;
use function strlen;
use function substr;
use function unpack;

class LiquidEngine{

    //what a block is to a liquid, as native/src/liquid.rs numbers them
    private const EMPTY = 0;
    private const FLOODABLE = 1;
    private const WATER = 3;
    private const FLOWING_WATER = 4;
    private const LAVA = 5;
    private const FLOWING_LAVA = 6;
    private const WATERY = 7;
    private const BUBBLE_COLUMN = 8;
    private const CONCRETE_POWDER = 9;
    private const SOUL_SAND = 10;
    private const MAGMA = 11;
    private const SOUL_SOIL = 12;
    private const BLUE_ICE = 13;

    private const DROPS = 1;
    private const FIZZ = 2;

    private const KINDS = [
        BlockIds::AIR => self::EMPTY,
        BlockIds::WATER => self::WATER,
        BlockIds::FLOWING_WATER => self::FLOWING_WATER,
        BlockIds::LAVA => self::LAVA,
        BlockIds::FLOWING_LAVA => self::FLOWING_LAVA,
        BlockIds::KELP => self::WATERY,
        BlockIds::SEAGRASS => self::WATERY,
        BlockIds::BUBBLE_COLUMN => self::BUBBLE_COLUMN,
        "minecraft:soul_sand" => self::SOUL_SAND,
        "minecraft:magma" => self::MAGMA,
        "minecraft:soul_soil" => self::SOUL_SOIL,
        "minecraft:blue_ice" => self::BLUE_ICE
    ];

    private const KEEPS_OUT = '/^minecraft:(\w*sign|\w*_banner|ladder|reeds|portal|end_portal|end_gateway|structure_void|light_block_\d+|powder_snow|' .
    '(dead_)?\w+_coral(_wall)?(_fan)?|sea_pickle|small_dripleaf_block|hanging_roots|glow_lichen|sculk_vein|\w*rail|\w+_pressure_plate|frog_spawn|underwater_torch)$/D';

    private static bool $taught = false;

    private World $world;

    private FFI $ffi;

    private int $handle;

    private bool $applying = false;

    private bool $closed = false;

    public function __construct(World $world){
        self::teach(Server::getInstance()->getBlockStateRegistry());

        $this->world = $world;
        $this->ffi = Native::get();
        $this->handle = $this->ffi->rx_liquid_new();
    }

    private static function teach(BlockStateRegistry $registry) : void{
        if(self::$taught){
            return;
        }

        $entries = [];

        foreach($registry->getBlockNames() as $name){
            $kind = self::KINDS[$name] ?? null;
            $extra = 0;

            if($kind === null && str_ends_with($name, "_concrete_powder") && $registry->isRegistered(substr($name, 0, -7))){
                $kind = self::CONCRETE_POWDER;
                $extra = BlockStateIds::get($registry->getDefaultState(substr($name, 0, -7)));
            }

            if($kind === null){
                if(BlockCollision::isSolid($registry->getDefaultState($name)) || preg_match(self::KEEPS_OUT, $name) === 1){
                    continue;
                }

                $kind = self::FLOODABLE;
            }

            foreach($registry->getAllStates($name) as $state){
                $entries[] = [BlockStateIds::get($state), $kind, self::levelOf($state, $kind), $extra];
            }
        }

        $count = count($entries);
        $ffi = Native::get();
        $states = $ffi->new("uint32_t[{$count}]");
        $kinds = $ffi->new("uint8_t[{$count}]");
        $levels = $ffi->new("uint8_t[{$count}]");
        $extras = $ffi->new("uint32_t[{$count}]");

        foreach($entries as $i => [$id, $kind, $level, $extra]){
            $states[$i] = $id;
            $kinds[$i] = $kind;
            $levels[$i] = $level;
            $extras[$i] = $extra;
        }

        $results = $ffi->new("uint32_t[5]");

        foreach([BlockIds::STONE, BlockIds::COBBLESTONE, BlockIds::OBSIDIAN, "minecraft:basalt", BlockIds::AIR] as $i => $name){
            $results[$i] = BlockStateIds::get($registry->getDefaultState($name));
        }

        $ffi->rx_liquid_blocks($states, $kinds, $levels, $extras, $count, $results);
        self::$taught = true;
    }

    public static function canReplace(BlockState $block) : bool{
        $name = $block->getName();
        $kind = self::KINDS[$name] ?? null;

        if($kind !== null){
            return $kind === self::EMPTY || $kind === self::WATER || $kind === self::FLOWING_WATER || $kind === self::LAVA || $kind === self::FLOWING_LAVA;
        }

        return !BlockCollision::isSolid($block) && preg_match(self::KEEPS_OUT, $name) !== 1;
    }

    private static function levelOf(BlockState $state, int $kind) : int{
        return match($kind){
            self::WATER, self::FLOWING_WATER, self::LAVA, self::FLOWING_LAVA => $state->getProperty("liquid_depth"),
            self::BUBBLE_COLUMN => $state->getProperty("drag_down") === true ? 1 : 0,
            default => 0
        };
    }

    public function close() : void{
        if(!$this->closed){
            $this->closed = true;
            $this->ffi->rx_liquid_free($this->handle);
        }
    }

    public function addChunk(Chunk $chunk) : void{
        $this->ffi->rx_liquid_add_chunk($this->handle, $chunk->getX(), $chunk->getZ(), $chunk->getHandle(), $this->world->getCurrentTick());
    }

    public function removeChunk(int $chunkX, int $chunkZ) : void{
        $this->ffi->rx_liquid_remove_chunk($this->handle, $chunkX, $chunkZ);
    }

    public function onBlockChanged(int $x, int $y, int $z) : void{
        if(!$this->applying){
            $this->ffi->rx_liquid_changed($this->handle, $x, $y, $z, $this->world->getCurrentTick());
        }
    }

    public function tick(int $currentTick) : void{
        $out = $this->ffi->new("char*");
        $length = $this->ffi->new("size_t");

        if($this->ffi->rx_liquid_tick($this->handle, $currentTick, FFI::addr($out), FFI::addr($length)) !== 1){
            return;
        }

        $data = Native::take($out, $length->cdata);

        if($data === ""){
            return;
        }

        $this->applying = true;

        try{
            for($offset = 0, $size = strlen($data); $offset < $size; $offset += 17){
                $change = unpack("lx/ly/lz/Vstate/Cflags", $data, $offset);
                $this->apply($change["x"], $change["y"], $change["z"], BlockStateIds::fromId($change["state"]), $change["flags"]);
            }
        }finally{
            $this->applying = false;
        }
    }

    private function apply(int $x, int $y, int $z, BlockState $state, int $flags) : void{
        $world = $this->world;

        if(($flags & self::DROPS) !== 0){
            $old = $world->getBlock($x, $y, $z);

            foreach(BlockBreakInfo::get($old->getName())->getDrops($old, Item::air()) as $item){
                $world->dropItem(new Vector3($x + 0.5, $y + 0.5, $z + 0.5), $item);
            }
        }

        $world->setBlock($x, $y, $z, $state);

        if(($flags & self::FIZZ) !== 0){
            $world->broadcastPacket($x, $z, LevelEventPacket::create(LevelEvent::SOUND_FIZZ, $x + 0.5, $y + 0.5, $z + 0.5, mt_rand(0, 1000)));
            $world->broadcastPacket($x, $z, LevelEventPacket::create(LevelEvent::PARTICLES_FIZZ_EFFECT, $x + 0.5, $y + 0.5, $z + 0.5));
        }
    }
}