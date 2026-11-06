//! The blocks generators place. Generators work with these small numbers; PHP turns each
//! state string into its state ID once, when the generator is made.

pub type Block = u8;

macro_rules! blocks {
    ($($name:ident = $state:literal,)*) => {
        #[allow(non_camel_case_types, clippy::upper_case_acronyms)]
        #[repr(u8)]
        enum Index { $($name,)* }

        $(pub const $name: Block = Index::$name as Block;)*

        /// Block state strings in the order of the numbers above.
        pub const STATES: &[&str] = &[$($state,)*];
    };
}

blocks! {
    AIR = "minecraft:air",
    STONE = "minecraft:stone",
    GRASS_BLOCK = "minecraft:grass_block",
    DIRT = "minecraft:dirt",
    COARSE_DIRT = "minecraft:coarse_dirt",
    PODZOL = "minecraft:podzol",
    MYCELIUM = "minecraft:mycelium",
    SAND = "minecraft:sand",
    RED_SAND = "minecraft:red_sand",
    SANDSTONE = "minecraft:sandstone",
    RED_SANDSTONE = "minecraft:red_sandstone",
    GRAVEL = "minecraft:gravel",
    CLAY = "minecraft:clay",
    BEDROCK = "minecraft:bedrock[infiniburn_bit=false]",
    BARRIER = "minecraft:barrier",
    WATER = "minecraft:water[liquid_depth=0]",
    LAVA = "minecraft:lava[liquid_depth=0]",
    ICE = "minecraft:ice",
    PACKED_ICE = "minecraft:packed_ice",
    SNOW_LAYER = "minecraft:snow_layer[covered_bit=false,height=0]",
    SNOW = "minecraft:snow",
    TERRACOTTA = "minecraft:hardened_clay",
    ORANGE_TERRACOTTA = "minecraft:orange_terracotta",
    YELLOW_TERRACOTTA = "minecraft:yellow_terracotta",
    BROWN_TERRACOTTA = "minecraft:brown_terracotta",
    RED_TERRACOTTA = "minecraft:red_terracotta",
    WHITE_TERRACOTTA = "minecraft:white_terracotta",
    LIGHT_GRAY_TERRACOTTA = "minecraft:light_gray_terracotta",

    COAL_ORE = "minecraft:coal_ore",
    IRON_ORE = "minecraft:iron_ore",
    COPPER_ORE = "minecraft:copper_ore",
    GOLD_ORE = "minecraft:gold_ore",
    REDSTONE_ORE = "minecraft:redstone_ore",
    LAPIS_ORE = "minecraft:lapis_ore",
    DIAMOND_ORE = "minecraft:diamond_ore",

    OAK_LOG = "minecraft:oak_log[pillar_axis=y]",
    OAK_LEAVES = "minecraft:oak_leaves[persistent_bit=false,update_bit=false]",
    BIRCH_LOG = "minecraft:birch_log[pillar_axis=y]",
    BIRCH_LEAVES = "minecraft:birch_leaves[persistent_bit=false,update_bit=false]",
    SPRUCE_LOG = "minecraft:spruce_log[pillar_axis=y]",
    SPRUCE_LEAVES = "minecraft:spruce_leaves[persistent_bit=false,update_bit=false]",
    JUNGLE_LOG = "minecraft:jungle_log[pillar_axis=y]",
    JUNGLE_LEAVES = "minecraft:jungle_leaves[persistent_bit=false,update_bit=false]",
    ACACIA_LOG = "minecraft:acacia_log[pillar_axis=y]",
    ACACIA_LEAVES = "minecraft:acacia_leaves[persistent_bit=false,update_bit=false]",
    DARK_OAK_LOG = "minecraft:dark_oak_log[pillar_axis=y]",
    DARK_OAK_LEAVES = "minecraft:dark_oak_leaves[persistent_bit=false,update_bit=false]",
    CHERRY_LOG = "minecraft:cherry_log[pillar_axis=y]",
    CHERRY_LEAVES = "minecraft:cherry_leaves[persistent_bit=false,update_bit=false]",
    MANGROVE_LOG = "minecraft:mangrove_log[pillar_axis=y]",
    MANGROVE_LEAVES = "minecraft:mangrove_leaves[persistent_bit=false,update_bit=false]",
    VINE_NORTH = "minecraft:vine[vine_direction_bits=4]",
    VINE_SOUTH = "minecraft:vine[vine_direction_bits=1]",
    VINE_WEST = "minecraft:vine[vine_direction_bits=2]",
    VINE_EAST = "minecraft:vine[vine_direction_bits=8]",

    SHORT_GRASS = "minecraft:short_grass",
    FERN = "minecraft:fern",
    TALL_GRASS_BOTTOM = "minecraft:tall_grass[upper_block_bit=false]",
    TALL_GRASS_TOP = "minecraft:tall_grass[upper_block_bit=true]",
    LARGE_FERN_BOTTOM = "minecraft:large_fern[upper_block_bit=false]",
    LARGE_FERN_TOP = "minecraft:large_fern[upper_block_bit=true]",
    SUNFLOWER_BOTTOM = "minecraft:sunflower[upper_block_bit=false]",
    SUNFLOWER_TOP = "minecraft:sunflower[upper_block_bit=true]",
    DANDELION = "minecraft:dandelion",
    POPPY = "minecraft:poppy",
    BLUE_ORCHID = "minecraft:blue_orchid",
    ALLIUM = "minecraft:allium",
    AZURE_BLUET = "minecraft:azure_bluet",
    RED_TULIP = "minecraft:red_tulip",
    ORANGE_TULIP = "minecraft:orange_tulip",
    WHITE_TULIP = "minecraft:white_tulip",
    PINK_TULIP = "minecraft:pink_tulip",
    OXEYE_DAISY = "minecraft:oxeye_daisy",
    CORNFLOWER = "minecraft:cornflower",
    LILY_OF_THE_VALLEY = "minecraft:lily_of_the_valley",
    PINK_PETALS = "minecraft:pink_petals[growth=3,minecraft:cardinal_direction=north]",
    DEAD_BUSH = "minecraft:deadbush",
    CACTUS = "minecraft:cactus[age=0]",
    SUGAR_CANE = "minecraft:reeds[age=0]",
    PUMPKIN = "minecraft:pumpkin[minecraft:cardinal_direction=south]",
    BROWN_MUSHROOM = "minecraft:brown_mushroom",
    RED_MUSHROOM = "minecraft:red_mushroom",
    BAMBOO = "minecraft:bamboo[age_bit=false,bamboo_leaf_size=no_leaves,bamboo_stalk_thickness=thick]",
    BAMBOO_TOP = "minecraft:bamboo[age_bit=false,bamboo_leaf_size=large_leaves,bamboo_stalk_thickness=thick]",
    MELON = "minecraft:melon_block",

    GRANITE = "minecraft:granite",
    DIORITE = "minecraft:diorite",
    ANDESITE = "minecraft:andesite",
    EMERALD_ORE = "minecraft:emerald_ore",
    INFESTED_STONE = "minecraft:infested_stone",
    COBBLESTONE = "minecraft:cobblestone",
    MOSSY_COBBLESTONE = "minecraft:mossy_cobblestone",
    LILY_PAD = "minecraft:waterlily",
    LILAC_BOTTOM = "minecraft:lilac[upper_block_bit=false]",
    LILAC_TOP = "minecraft:lilac[upper_block_bit=true]",
    ROSE_BUSH_BOTTOM = "minecraft:rose_bush[upper_block_bit=false]",
    ROSE_BUSH_TOP = "minecraft:rose_bush[upper_block_bit=true]",
    PEONY_BOTTOM = "minecraft:peony[upper_block_bit=false]",
    PEONY_TOP = "minecraft:peony[upper_block_bit=true]",
    BROWN_MUSHROOM_BLOCK_0 = "minecraft:brown_mushroom_block[huge_mushroom_bits=0]",
    BROWN_MUSHROOM_BLOCK_1 = "minecraft:brown_mushroom_block[huge_mushroom_bits=1]",
    BROWN_MUSHROOM_BLOCK_2 = "minecraft:brown_mushroom_block[huge_mushroom_bits=2]",
    BROWN_MUSHROOM_BLOCK_3 = "minecraft:brown_mushroom_block[huge_mushroom_bits=3]",
    BROWN_MUSHROOM_BLOCK_4 = "minecraft:brown_mushroom_block[huge_mushroom_bits=4]",
    BROWN_MUSHROOM_BLOCK_5 = "minecraft:brown_mushroom_block[huge_mushroom_bits=5]",
    BROWN_MUSHROOM_BLOCK_6 = "minecraft:brown_mushroom_block[huge_mushroom_bits=6]",
    BROWN_MUSHROOM_BLOCK_7 = "minecraft:brown_mushroom_block[huge_mushroom_bits=7]",
    BROWN_MUSHROOM_BLOCK_8 = "minecraft:brown_mushroom_block[huge_mushroom_bits=8]",
    BROWN_MUSHROOM_BLOCK_9 = "minecraft:brown_mushroom_block[huge_mushroom_bits=9]",
    BROWN_MUSHROOM_BLOCK_10 = "minecraft:brown_mushroom_block[huge_mushroom_bits=10]",
    BROWN_MUSHROOM_BLOCK_11 = "minecraft:brown_mushroom_block[huge_mushroom_bits=11]",
    BROWN_MUSHROOM_BLOCK_12 = "minecraft:brown_mushroom_block[huge_mushroom_bits=12]",
    BROWN_MUSHROOM_BLOCK_13 = "minecraft:brown_mushroom_block[huge_mushroom_bits=13]",
    BROWN_MUSHROOM_BLOCK_14 = "minecraft:brown_mushroom_block[huge_mushroom_bits=14]",
    BROWN_MUSHROOM_BLOCK_15 = "minecraft:brown_mushroom_block[huge_mushroom_bits=15]",
    RED_MUSHROOM_BLOCK_0 = "minecraft:red_mushroom_block[huge_mushroom_bits=0]",
    RED_MUSHROOM_BLOCK_1 = "minecraft:red_mushroom_block[huge_mushroom_bits=1]",
    RED_MUSHROOM_BLOCK_2 = "minecraft:red_mushroom_block[huge_mushroom_bits=2]",
    RED_MUSHROOM_BLOCK_3 = "minecraft:red_mushroom_block[huge_mushroom_bits=3]",
    RED_MUSHROOM_BLOCK_4 = "minecraft:red_mushroom_block[huge_mushroom_bits=4]",
    RED_MUSHROOM_BLOCK_5 = "minecraft:red_mushroom_block[huge_mushroom_bits=5]",
    RED_MUSHROOM_BLOCK_6 = "minecraft:red_mushroom_block[huge_mushroom_bits=6]",
    RED_MUSHROOM_BLOCK_7 = "minecraft:red_mushroom_block[huge_mushroom_bits=7]",
    RED_MUSHROOM_BLOCK_8 = "minecraft:red_mushroom_block[huge_mushroom_bits=8]",
    RED_MUSHROOM_BLOCK_9 = "minecraft:red_mushroom_block[huge_mushroom_bits=9]",
    RED_MUSHROOM_BLOCK_10 = "minecraft:red_mushroom_block[huge_mushroom_bits=10]",
    RED_MUSHROOM_BLOCK_11 = "minecraft:red_mushroom_block[huge_mushroom_bits=11]",
    RED_MUSHROOM_BLOCK_12 = "minecraft:red_mushroom_block[huge_mushroom_bits=12]",
    RED_MUSHROOM_BLOCK_13 = "minecraft:red_mushroom_block[huge_mushroom_bits=13]",
    RED_MUSHROOM_BLOCK_14 = "minecraft:red_mushroom_block[huge_mushroom_bits=14]",
    RED_MUSHROOM_BLOCK_15 = "minecraft:red_mushroom_block[huge_mushroom_bits=15]",
    MUSHROOM_STEM = "minecraft:mushroom_stem[huge_mushroom_bits=10]",
    COCOA_0_0 = "minecraft:cocoa[age=0,direction=0]",
    COCOA_0_1 = "minecraft:cocoa[age=1,direction=0]",
    COCOA_0_2 = "minecraft:cocoa[age=2,direction=0]",
    COCOA_1_0 = "minecraft:cocoa[age=0,direction=1]",
    COCOA_1_1 = "minecraft:cocoa[age=1,direction=1]",
    COCOA_1_2 = "minecraft:cocoa[age=2,direction=1]",
    COCOA_2_0 = "minecraft:cocoa[age=0,direction=2]",
    COCOA_2_1 = "minecraft:cocoa[age=1,direction=2]",
    COCOA_2_2 = "minecraft:cocoa[age=2,direction=2]",
    COCOA_3_0 = "minecraft:cocoa[age=0,direction=3]",
    COCOA_3_1 = "minecraft:cocoa[age=1,direction=3]",
    COCOA_3_2 = "minecraft:cocoa[age=2,direction=3]",
}

/// A cap block of a huge mushroom, `bits` being which sides show the cap, as the game numbers
/// them from 0 to 15.
pub fn brown_mushroom_block(bits: u8) -> Block {
    BROWN_MUSHROOM_BLOCK_0 + bits.min(15)
}

pub fn red_mushroom_block(bits: u8) -> Block {
    RED_MUSHROOM_BLOCK_0 + bits.min(15)
}

pub fn is_mushroom_block(block: Block) -> bool {
    (BROWN_MUSHROOM_BLOCK_0..=MUSHROOM_STEM).contains(&block)
}

/// Cocoa on the side of a jungle log: `direction` from 0 to 3 points at the log (south, west,
/// north, east), `age` from 0 to 2.
pub fn cocoa(direction: u8, age: u8) -> Block {
    COCOA_0_0 + direction.min(3) * 3 + age.min(2)
}

pub fn is_cocoa(block: Block) -> bool {
    (COCOA_0_0..=COCOA_3_2).contains(&block)
}

pub fn count() -> usize {
    STATES.len()
}

/// Blocks the sun and the beam of a feature cannot go through, and that plants stand on.
pub fn is_solid(block: Block) -> bool {
    !matches!(block, AIR | WATER | LAVA | ICE | SNOW_LAYER | BARRIER) && !is_plant(block) && !is_leaves(block) && !is_vine(block)
}

pub fn is_leaves(block: Block) -> bool {
    matches!(block, OAK_LEAVES | BIRCH_LEAVES | SPRUCE_LEAVES | JUNGLE_LEAVES | ACACIA_LEAVES | DARK_OAK_LEAVES | CHERRY_LEAVES | MANGROVE_LEAVES)
}

pub fn is_log(block: Block) -> bool {
    matches!(block, OAK_LOG | BIRCH_LOG | SPRUCE_LOG | JUNGLE_LOG | ACACIA_LOG | DARK_OAK_LOG | CHERRY_LOG | MANGROVE_LOG)
}

pub fn is_vine(block: Block) -> bool {
    matches!(block, VINE_NORTH | VINE_SOUTH | VINE_WEST | VINE_EAST)
}

pub fn is_plant(block: Block) -> bool {
    matches!(
        block,
        SHORT_GRASS | FERN | TALL_GRASS_BOTTOM | TALL_GRASS_TOP | LARGE_FERN_BOTTOM | LARGE_FERN_TOP | SUNFLOWER_BOTTOM | SUNFLOWER_TOP
            | DANDELION | POPPY | BLUE_ORCHID | ALLIUM | AZURE_BLUET | RED_TULIP | ORANGE_TULIP | WHITE_TULIP | PINK_TULIP
            | OXEYE_DAISY | CORNFLOWER | LILY_OF_THE_VALLEY | PINK_PETALS | DEAD_BUSH | SUGAR_CANE | BROWN_MUSHROOM | RED_MUSHROOM
            | LILAC_BOTTOM | LILAC_TOP | ROSE_BUSH_BOTTOM | ROSE_BUSH_TOP | PEONY_BOTTOM | PEONY_TOP
    )
}

/// What a tree can grow through, and a plant be replaced by.
pub fn is_replaceable(block: Block) -> bool {
    matches!(block, AIR | SNOW_LAYER) || is_leaves(block) || is_plant(block) || is_vine(block)
}

/// Ground grass, flowers and trees grow on.
pub fn is_soil(block: Block) -> bool {
    matches!(block, GRASS_BLOCK | DIRT | COARSE_DIRT | PODZOL | MYCELIUM)
}