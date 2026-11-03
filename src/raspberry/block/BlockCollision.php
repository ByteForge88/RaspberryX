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

    /** @var array<string, bool> */
    private static array $cache = [];

    private function __construct(){
    }

    public static function isSolid(BlockState $block) : bool{
        $name = $block->getName();

        return self::$cache[$name] ??= preg_match(self::PASSABLE, $name) !== 1;
    }
}