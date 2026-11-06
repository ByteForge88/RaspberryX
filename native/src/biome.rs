//! Biomes: their Bedrock ID and how they look, the ground they have, what grows on it.

use crate::generator::blocks::{self, Block};

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Tree {
    Oak,
    Birch,
    Spruce,
    TallSpruce,
    Jungle,
    Acacia,
    DarkOak,
    Cherry,
    Swamp,
    Mangrove,
}

#[derive(Clone, Copy, Debug, PartialEq, Eq)]
pub enum Ground {
    Normal,
    Desert,
    Badlands,
    Podzol,
    Mycelium,
}

pub struct Biome {
    /// the Bedrock biome ID
    pub id: u16,
    pub name: &'static str,
    pub top: Block,
    pub filler: Block,
    pub ground: Ground,
    /// gets snow on the ground and ice on water
    pub snowy: bool,
    /// trees per chunk, and which with how much weight
    pub trees: u32,
    pub tree_kinds: &'static [(Tree, u32)],
    /// a lone tree now and then in an open biome, one chunk in this many
    pub stray_tree: u32,
    pub grass: u32,
    pub ferns: bool,
    pub tall_grass: u32,
    pub flowers: u32,
    pub flower_kinds: &'static [Block],
    pub cactus: u32,
    pub dead_bushes: u32,
}

const DEFAULT_FLOWERS: &[Block] = &[blocks::DANDELION, blocks::DANDELION, blocks::POPPY];
const PLAINS_FLOWERS: &[Block] = &[blocks::DANDELION, blocks::POPPY, blocks::AZURE_BLUET, blocks::OXEYE_DAISY, blocks::CORNFLOWER, blocks::RED_TULIP, blocks::ORANGE_TULIP, blocks::WHITE_TULIP, blocks::PINK_TULIP];
const FOREST_FLOWERS: &[Block] = &[blocks::DANDELION, blocks::POPPY, blocks::LILY_OF_THE_VALLEY];
const FLOWER_FOREST_FLOWERS: &[Block] = &[blocks::DANDELION, blocks::POPPY, blocks::ALLIUM, blocks::AZURE_BLUET, blocks::RED_TULIP, blocks::ORANGE_TULIP, blocks::WHITE_TULIP, blocks::PINK_TULIP, blocks::OXEYE_DAISY, blocks::CORNFLOWER, blocks::LILY_OF_THE_VALLEY];
const MEADOW_FLOWERS: &[Block] = &[blocks::ALLIUM, blocks::AZURE_BLUET, blocks::OXEYE_DAISY, blocks::CORNFLOWER, blocks::DANDELION, blocks::POPPY];
const SWAMP_FLOWERS: &[Block] = &[blocks::BLUE_ORCHID];
const CHERRY_FLOWERS: &[Block] = &[blocks::PINK_PETALS];

macro_rules! biome {
    ($id:expr, $name:literal, $($field:ident: $value:expr),* $(,)?) => {
        Biome { id: $id, name: $name, $($field: $value,)* ..BASE }
    };
}

const BASE: Biome = Biome {
    id: 1,
    name: "plains",
    top: blocks::GRASS_BLOCK,
    filler: blocks::DIRT,
    ground: Ground::Normal,
    snowy: false,
    trees: 0,
    tree_kinds: &[(Tree::Oak, 1)],
    stray_tree: 0,
    grass: 2,
    ferns: false,
    tall_grass: 0,
    flowers: 2,
    flower_kinds: DEFAULT_FLOWERS,
    cactus: 0,
    dead_bushes: 0,
};

pub const OCEAN: Biome = biome!(0, "ocean", grass: 0, flowers: 0);
pub const FROZEN_OCEAN: Biome = biome!(46, "frozen_ocean", snowy: true, grass: 0, flowers: 0);
pub const WARM_OCEAN: Biome = biome!(40, "warm_ocean", grass: 0, flowers: 0);
pub const BEACH: Biome = biome!(16, "beach", top: blocks::SAND, filler: blocks::SAND, grass: 0, flowers: 0);
pub const SNOWY_BEACH: Biome = biome!(26, "snowy_beach", top: blocks::SAND, filler: blocks::SAND, snowy: true, grass: 0, flowers: 0);

pub const PLAINS: Biome = biome!(1, "plains", stray_tree: 10, grass: 10, tall_grass: 2, flowers: 4, flower_kinds: PLAINS_FLOWERS);
pub const SUNFLOWER_PLAINS: Biome = biome!(129, "sunflower_plains", stray_tree: 10, grass: 10, tall_grass: 2, flowers: 4, flower_kinds: PLAINS_FLOWERS);
pub const MEADOW: Biome = biome!(186, "meadow", stray_tree: 6, tree_kinds: &[(Tree::Oak, 1), (Tree::Birch, 1)], grass: 12, tall_grass: 4, flowers: 8, flower_kinds: MEADOW_FLOWERS);
pub const FOREST: Biome = biome!(4, "forest", trees: 8, tree_kinds: &[(Tree::Oak, 4), (Tree::Birch, 1)], flower_kinds: FOREST_FLOWERS);
pub const BIRCH_FOREST: Biome = biome!(27, "birch_forest", trees: 8, tree_kinds: &[(Tree::Birch, 1)], flower_kinds: FOREST_FLOWERS);
pub const FLOWER_FOREST: Biome = biome!(132, "flower_forest", trees: 4, tree_kinds: &[(Tree::Oak, 3), (Tree::Birch, 1)], flowers: 16, flower_kinds: FLOWER_FOREST_FLOWERS);
pub const DARK_FOREST: Biome = biome!(29, "dark_forest", trees: 12, tree_kinds: &[(Tree::DarkOak, 6), (Tree::Oak, 1), (Tree::Birch, 1)], flower_kinds: FOREST_FLOWERS);
pub const CHERRY_GROVE: Biome = biome!(192, "cherry_grove", trees: 3, tree_kinds: &[(Tree::Cherry, 1)], grass: 6, flowers: 10, flower_kinds: CHERRY_FLOWERS);
pub const TAIGA: Biome = biome!(5, "taiga", trees: 8, tree_kinds: &[(Tree::Spruce, 2), (Tree::TallSpruce, 1)], grass: 1, ferns: true, flowers: 0);
pub const SNOWY_TAIGA: Biome = biome!(30, "snowy_taiga", snowy: true, trees: 8, tree_kinds: &[(Tree::Spruce, 2), (Tree::TallSpruce, 1)], grass: 1, ferns: true, flowers: 0);
pub const OLD_GROWTH_TAIGA: Biome = biome!(32, "old_growth_pine_taiga", ground: Ground::Podzol, trees: 9, tree_kinds: &[(Tree::TallSpruce, 3), (Tree::Spruce, 1)], grass: 6, ferns: true, tall_grass: 2, flowers: 0);
pub const JUNGLE: Biome = biome!(21, "jungle", trees: 20, tree_kinds: &[(Tree::Jungle, 3), (Tree::Oak, 1)], grass: 20, ferns: true, tall_grass: 4, flowers: 4);
pub const BAMBOO_JUNGLE: Biome = biome!(48, "bamboo_jungle", trees: 6, tree_kinds: &[(Tree::Jungle, 2), (Tree::Oak, 1)], grass: 12, ferns: true, flowers: 2);
pub const SPARSE_JUNGLE: Biome = biome!(23, "sparse_jungle", trees: 2, tree_kinds: &[(Tree::Jungle, 1), (Tree::Oak, 1)], grass: 16, ferns: true, flowers: 4);
pub const SAVANNA: Biome = biome!(35, "savanna", trees: 1, tree_kinds: &[(Tree::Acacia, 4), (Tree::Oak, 1)], grass: 20, tall_grass: 7, flowers: 1);
pub const SWAMP: Biome = biome!(6, "swamp", trees: 2, tree_kinds: &[(Tree::Swamp, 1)], grass: 5, flowers: 1, flower_kinds: SWAMP_FLOWERS);
pub const MANGROVE_SWAMP: Biome = biome!(191, "mangrove_swamp", trees: 6, tree_kinds: &[(Tree::Mangrove, 1)], grass: 0, flowers: 0);
pub const DESERT: Biome = biome!(2, "desert", top: blocks::SAND, filler: blocks::SAND, ground: Ground::Desert, grass: 0, flowers: 0, cactus: 10, dead_bushes: 2);
pub const BADLANDS: Biome = biome!(37, "badlands", top: blocks::RED_SAND, filler: blocks::TERRACOTTA, ground: Ground::Badlands, grass: 0, flowers: 0, cactus: 5, dead_bushes: 20);
pub const SNOWY_PLAINS: Biome = biome!(12, "snowy_plains", snowy: true, stray_tree: 10, tree_kinds: &[(Tree::Spruce, 1)], grass: 0, flowers: 0);
pub const ICE_SPIKES: Biome = biome!(140, "ice_spikes", top: blocks::SNOW, filler: blocks::DIRT, snowy: true, grass: 0, flowers: 0);
pub const MUSHROOM_FIELDS: Biome = biome!(14, "mushroom_fields", top: blocks::MYCELIUM, ground: Ground::Mycelium, grass: 0, flowers: 0);

/// The climate kinds of the classic world generator, each with the biomes of today it turns
/// into. Which one a spot gets is up to another noise, so they come in patches.
pub const TUNDRA: &[&Biome] = &[&SNOWY_PLAINS, &SNOWY_PLAINS, &SNOWY_TAIGA, &ICE_SPIKES];
pub const TAIGA_KINDS: &[&Biome] = &[&TAIGA, &TAIGA, &SNOWY_TAIGA, &OLD_GROWTH_TAIGA];
pub const SWAMP_KINDS: &[&Biome] = &[&SWAMP, &SWAMP, &MANGROVE_SWAMP];
pub const SAVANNA_KINDS: &[&Biome] = &[&SAVANNA, &SAVANNA, &PLAINS];
pub const SHRUBLAND: &[&Biome] = &[&PLAINS, &MEADOW, &MEADOW, &MUSHROOM_FIELDS];
pub const FOREST_KINDS: &[&Biome] = &[&FOREST, &FOREST, &BIRCH_FOREST, &FLOWER_FOREST, &DARK_FOREST, &CHERRY_GROVE];
pub const SEASONAL_FOREST: &[&Biome] = &[&BIRCH_FOREST, &FOREST, &FLOWER_FOREST, &CHERRY_GROVE];
pub const RAINFOREST: &[&Biome] = &[&JUNGLE, &JUNGLE, &BAMBOO_JUNGLE, &SPARSE_JUNGLE];
pub const PLAINS_KINDS: &[&Biome] = &[&PLAINS, &PLAINS, &SUNFLOWER_PLAINS, &MEADOW];
pub const DESERT_KINDS: &[&Biome] = &[&DESERT, &DESERT, &BADLANDS];

/// The classic lookup from temperature and rainfall (both 0 to 1) to a climate.
pub fn climate(temperature: f64, rainfall: f64) -> &'static [&'static Biome] {
    let rainfall = rainfall * temperature;

    if temperature < 0.1 {
        TUNDRA
    } else if rainfall < 0.2 {
        if temperature < 0.5 {
            TUNDRA
        } else if temperature < 0.95 {
            SAVANNA_KINDS
        } else {
            DESERT_KINDS
        }
    } else if rainfall > 0.5 && temperature < 0.7 {
        SWAMP_KINDS
    } else if temperature < 0.5 {
        TAIGA_KINDS
    } else if temperature < 0.97 {
        if rainfall < 0.35 { SHRUBLAND } else { FOREST_KINDS }
    } else if rainfall < 0.45 {
        PLAINS_KINDS
    } else if rainfall < 0.9 {
        SEASONAL_FOREST
    } else {
        RAINFOREST
    }
}
