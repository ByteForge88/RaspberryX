<?php

declare(strict_types=1);

namespace raspberry\block;

use function preg_match;

class BlockCollision{

    //since we aren't using objects for blocks/items, but it still faster than pocketmine
    private const PASSABLE = '/^minecraft:(' .
    'air|water|flowing_water|lava|flowing_lava|bubble_column|fire|soul_fire|portal|end_portal|trip_wire|structure_void|light_block_\d+|powder_snow|web|' .
    '\w+_sapling|(short|tall)_(dry_)?grass|large_fern|fern|deadbush|bush|firefly_bush|seagrass|kelp|reeds|vine|weeping_vines|twisting_vines|cave_vines\w*|' .
    'glow_lichen|sculk_vein|hanging_roots|spore_blossom|(crimson|warped)_(roots|fungus)|nether_sprouts|(brown|red)_mushroom|' .
    'wheat|carrots|potatoes|beetroot|(melon|pumpkin)_stem|nether_wart|sweet_berry_bush|(torchflower|pitcher)_crop|' .
    '(golden_)?dandelion|poppy|blue_orchid|allium|azure_bluet|\w+_tulip|oxeye_daisy|cornflower|lily_of_the_valley|wither_rose|torchflower|' .
    'sunflower|lilac|rose_bush|peony|pitcher_plant|pink_petals|wildflowers|leaf_litter|(open|closed)_eyeblossom|cactus_flower|small_dripleaf_block|frog_spawn|' .
    '(dead_)?\w+_coral(_wall)?(_fan)?|\w*torch(_\w+)?|\w+_button|\w+_pressure_plate|\w*_sign|\w*_banner|\w*carpet|\w*rail|' .
    'redstone_wire|lever|tripwire_hook|ladder|snow_layer' .
    ')$/D';

    /** solid blocks that are not full, see-through cubes: a head inside them does not suffocate, and they do not block a chest lid */
    private const NOT_FULL = '/^minecraft:(' .
    '\w*glass(_pane)?|\w*leaves(_flowered)?|\w+_slab|\w*stairs|\w*fence(_gate)?|\w*_wall|\w*door|\w*trapdoor|\w*bars|\w*chain|' .
    '\w*chest|\w*shulker_box|\w*bed|\w*cake|\w*anvil|\w*lantern|cauldron|hopper|brewing_stand|enchanting_table|end_rod|\w*lightning_rod|' .
    'flower_pot|\w+_skull|\w+_head|bell|\w*campfire|daylight_detector(_inverted)?|conduit|beacon|ice|frosted_ice|slime|honey_block|scaffolding|' .
    'cactus|chorus_\w+|grindstone|stonecutter_block|lectern|composter|\w*amethyst_(bud|cluster)|pointed_dripstone|\w*azalea|big_dripleaf|' .
    'bamboo|sea_pickle|turtle_egg|sniffer_egg|(glow_)?frame|heavy_core|decorated_pot|mob_spawner|trial_spawner|vault|\w*copper_grate|' .
    'piston_arm_collision|sticky_piston_arm_collision|moving_block|end_portal_frame|dragon_egg|\w*candle|\w+_shelf|crafter|' .
    '(powered|unpowered)_(repeater|comparator)|\w*_hanging_sign|frog_spawn|mangrove_roots|\w*copper_golem_statue|creaking_heart' .
    ')$/D';

    /** @var array<string, bool> */
    private static array $cache = [];

    /** @var array<string, bool> */
    private static array $fullCache = [];

    private function __construct(){
    }

    public static function isSolid(BlockState $block) : bool{
        $name = $block->getName();

        return self::$cache[$name] ??= preg_match(self::PASSABLE, $name) !== 1;
    }

    /**
     * Whether the block is a full cube you cannot see through: a player whose head is in one
     * suffocates, and one above a chest keeps its lid shut.
     */
    public static function isFullCube(BlockState $block) : bool{
        $name = $block->getName();

        return self::$fullCache[$name] ??= self::isSolid($block) && preg_match(self::NOT_FULL, $name) !== 1;
    }
}