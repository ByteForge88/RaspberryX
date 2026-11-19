<?php

declare(strict_types=1);

namespace raspberry\entity\ai;

use raspberry\block\BlockCollision;
use raspberry\block\BlockState;
use raspberry\block\BlockStateIds;

use raspberry\native\Native;

use function count;
use function preg_match;

class MobBlocks{

    /** the low 5 bits are the height of the box in sixteenths of a block */
    public const int FULL = 16;
    public const int WATER = 0x0100;
    public const int LAVA = 0x0200;
    public const int CLIMB = 0x0400;
    public const int DAMAGE = 0x0800;
    public const int GRASS_BLOCK = 0x1000;
    public const int GRASS_PLANT = 0x2000;
    public const int FIRE = 0x4000;
    public const int BED = 0x8000;

    /** how the block changes the way mobs move, it takes bits 5 to 7 of the low byte */
    public const int COBWEB = 0x20;
    public const int SWEET_BERRY_BUSH = 0x40;
    public const int POWDER_SNOW = 0x60;
    public const int SLIME = 0x80;
    public const int HONEY = 0xa0;
    public const int SOUL_SAND = 0xc0;
    public const int HAY = 0xe0;

    private const string FENCES = '/^minecraft:(\w+_)?(fence|wall)$|^minecraft:(\w+_)?fence_gate$/';

    private const string SLABS = '/^minecraft:\w+_slab\d?$/';

    private const string DOORS = '/^minecraft:(\w+_)?(door|trapdoor)$/';

    private const string CLIMBABLE = '/^minecraft:(ladder|vine|scaffolding|\w+_vines)$/';

    /** kelp and seagrass only grow in water, a mob in them is in water */
    private const string WATERY = '/^minecraft:(water|flowing_water|bubble_column|kelp|seagrass)$/';

    private const string EATEN = '/^minecraft:(short_grass|fern|short_dry_grass|tall_dry_grass)$/';

    private function __construct(){
    }

    public static function describe(BlockState $state) : int{
        $name = $state->getName();

        if(preg_match(self::WATERY, $name) === 1){
            return self::WATER;
        }

        if($name === "minecraft:lava" || $name === "minecraft:flowing_lava"){
            return self::LAVA;
        }

        if(!BlockCollision::isSolid($state)){
            return match(true){
                $name === "minecraft:fire" || $name === "minecraft:soul_fire" => self::DAMAGE | self::FIRE,
                $name === "minecraft:sweet_berry_bush" => self::SWEET_BERRY_BUSH | (self::property($state, "growth") === 0 ? 0 : self::DAMAGE),
                $name === "minecraft:wither_rose" => self::DAMAGE,
                $name === "minecraft:web" => self::COBWEB,
                $name === "minecraft:powder_snow" => self::POWDER_SNOW,
                $name === "minecraft:snow_layer" => 2 * (int) self::property($state, "height"),
                preg_match(self::EATEN, $name) === 1 => self::GRASS_PLANT,
                preg_match(self::CLIMBABLE, $name) === 1 => self::CLIMB,
                default => 0
            };
        }

        $open = self::property($state, "open_bit") === true;

        return match(true){
            $name === "minecraft:grass_block" => self::FULL | self::GRASS_BLOCK,
            $name === "minecraft:cactus" || $name === "minecraft:magma" => self::FULL | self::DAMAGE,
            $name === "minecraft:campfire" || $name === "minecraft:soul_campfire" => 7 | (self::property($state, "extinguished") === false ? self::DAMAGE : 0),
            preg_match(self::FENCES, $name) === 1 => $open ? 0 : 24,
            preg_match(self::DOORS, $name) === 1 => $open ? 0 : (self::property($state, "upside_down_bit") === false ? 3 : self::FULL),
            preg_match(self::SLABS, $name) === 1 && preg_match('/double/', $name) !== 1 => self::property($state, "minecraft:vertical_half") === "top" ? self::FULL : 8,
            $name === "minecraft:slime" => self::FULL | self::SLIME,
            $name === "minecraft:honey_block" => 15 | self::HONEY,
            $name === "minecraft:hay_block" => self::FULL | self::HAY,
            $name === "minecraft:farmland" || $name === "minecraft:grass_path" => 15,
            $name === "minecraft:soul_sand" => 14 | self::SOUL_SAND,
            $name === "minecraft:mud" => 14,
            $name === "minecraft:bed" => 9 | self::BED,
            $name === "minecraft:big_dripleaf" => self::property($state, "big_dripleaf_head") === true && self::property($state, "big_dripleaf_tilt") !== "full_tilt" ? 15 : 0,
            default => self::FULL
        };
    }

    private static function property(BlockState $state, string $property) : string|int|bool|null{
        return $state->hasProperty($property) ? $state->getProperty($property) : null;
    }

    /**
     * @param list<int> $stateIds
     */
    public static function teach(array $stateIds) : void{
        $count = count($stateIds);

        if($count === 0){
            return;
        }

        $ffi = Native::get();
        $states = $ffi->new("uint32_t[{$count}]");
        $flags = $ffi->new("uint16_t[{$count}]");

        foreach($stateIds as $index => $id){
            $states[$index] = $id;
            $flags[$index] = self::describe(BlockStateIds::fromId($id));
        }

        $ffi->rx_mobs_blocks($states, $flags, $count);
    }
}