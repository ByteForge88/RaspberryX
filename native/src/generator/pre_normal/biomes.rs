//! The biomes of 1.8 with what the generator needs of them. Bedrock still numbers its biomes
//! the way Java did then, so the IDs go to the client as they are.

use crate::generator::blocks::{self, Block};

#[rustfmt::skip]
pub mod id {
    pub const OCEAN: i32 = 0;
    pub const PLAINS: i32 = 1;
    pub const DESERT: i32 = 2;
    pub const EXTREME_HILLS: i32 = 3;
    pub const FOREST: i32 = 4;
    pub const TAIGA: i32 = 5;
    pub const SWAMPLAND: i32 = 6;
    pub const RIVER: i32 = 7;
    pub const FROZEN_OCEAN: i32 = 10;
    pub const FROZEN_RIVER: i32 = 11;
    pub const ICE_PLAINS: i32 = 12;
    pub const ICE_MOUNTAINS: i32 = 13;
    pub const MUSHROOM_ISLAND: i32 = 14;
    pub const MUSHROOM_ISLAND_SHORE: i32 = 15;
    pub const BEACH: i32 = 16;
    pub const DESERT_HILLS: i32 = 17;
    pub const FOREST_HILLS: i32 = 18;
    pub const TAIGA_HILLS: i32 = 19;
    pub const EXTREME_HILLS_EDGE: i32 = 20;
    pub const JUNGLE: i32 = 21;
    pub const JUNGLE_HILLS: i32 = 22;
    pub const JUNGLE_EDGE: i32 = 23;
    pub const DEEP_OCEAN: i32 = 24;
    pub const STONE_BEACH: i32 = 25;
    pub const COLD_BEACH: i32 = 26;
    pub const BIRCH_FOREST: i32 = 27;
    pub const BIRCH_FOREST_HILLS: i32 = 28;
    pub const ROOFED_FOREST: i32 = 29;
    pub const COLD_TAIGA: i32 = 30;
    pub const COLD_TAIGA_HILLS: i32 = 31;
    pub const MEGA_TAIGA: i32 = 32;
    pub const MEGA_TAIGA_HILLS: i32 = 33;
    pub const EXTREME_HILLS_PLUS: i32 = 34;
    pub const SAVANNA: i32 = 35;
    pub const SAVANNA_PLATEAU: i32 = 36;
    pub const MESA: i32 = 37;
    pub const MESA_PLATEAU_F: i32 = 38;
    pub const MESA_PLATEAU: i32 = 39;
}

/// Biomes of one class count as the same kind where layers compare them.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Class {
    Ocean,
    Plains,
    Desert,
    Hills,
    Forest,
    Taiga,
    Swamp,
    River,
    Hell,
    End,
    Snow,
    Mushroom,
    Beach,
    Jungle,
    StoneBeach,
    Savanna,
    Mesa,
}

/// How a biome lays its top blocks.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Surface {
    Normal,
    /// stone on steep spots, `gravel` adds gravel for the M variants
    Hills { stone: bool, gravel: bool },
    /// podzol and coarse dirt
    MegaTaiga,
    /// puddles and lily pads at sea level
    Swamp,
    SavannaMutated,
    Mesa { bryce: bool, forest: bool },
}

/// What a biome grows, beyond the counts.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Style {
    Normal,
    Plains { sunflowers: bool },
    Desert,
    Hills,
    Forest { kind: u8 },
    /// the birch M forests, half of them tall birches
    TallBirch,
    /// the roofed M forest, which decorates like its base
    RoofedMutated,
    Taiga { kind: u8 },
    Swamp,
    Snow { spikes: bool },
    Jungle { edge: bool },
    Savanna,
    SavannaMutated,
    Mesa,
    Mushroom,
    /// a plain mutation: the base's decorator, without its extras
    Mutated(i32),
}

#[derive(Clone, Copy, Debug)]
pub struct Decorator {
    pub trees: i32,
    pub flowers: i32,
    pub grass: i32,
    pub dead_bushes: i32,
    pub mushrooms: i32,
    pub reeds: i32,
    pub cacti: i32,
    pub sand: i32,
    pub gravel: i32,
    pub clay: i32,
    pub big_mushrooms: i32,
    pub waterlilies: i32,
}

const DEFAULT: Decorator = Decorator { trees: 0, flowers: 2, grass: 1, dead_bushes: 0, mushrooms: 0, reeds: 0, cacti: 0, sand: 3, gravel: 1, clay: 1, big_mushrooms: 0, waterlilies: 0 };

#[derive(Clone, Copy, Debug)]
pub struct LegacyBiome {
    pub id: i32,
    pub min_height: f32,
    pub max_height: f32,
    pub temperature: f32,
    pub rainfall: f32,
    pub snowy: bool,
    pub class: Class,
    pub top: Block,
    pub filler: Block,
    pub surface: Surface,
    pub style: Style,
    pub decorator: Decorator,
}

const BASE: LegacyBiome = LegacyBiome {
    id: 0,
    min_height: 0.1,
    max_height: 0.2,
    temperature: 0.5,
    rainfall: 0.5,
    snowy: false,
    class: Class::Plains,
    top: blocks::GRASS_BLOCK,
    filler: blocks::DIRT,
    surface: Surface::Normal,
    style: Style::Normal,
    decorator: DEFAULT,
};

const OCEANS: (f32, f32) = (-1.0, 0.1);
const DEEP_OCEANS: (f32, f32) = (-1.8, 0.1);
const SHALLOW_WATERS: (f32, f32) = (-0.5, 0.0);
const LOW_PLAINS: (f32, f32) = (0.125, 0.05);
const MID_PLAINS: (f32, f32) = (0.2, 0.2);
const LOW_HILLS: (f32, f32) = (0.45, 0.3);
const HIGH_PLATEAUS: (f32, f32) = (1.5, 0.025);
const MID_HILLS: (f32, f32) = (1.0, 0.5);
const SHORES: (f32, f32) = (0.0, 0.025);
const ROCKY_WATERS: (f32, f32) = (0.1, 0.8);
const LOW_ISLANDS: (f32, f32) = (0.2, 0.3);
const PARTIALLY_SUBMERGED: (f32, f32) = (-0.2, 0.1);
const DEFAULT_HEIGHT: (f32, f32) = (0.1, 0.2);

const fn biome(id: i32, class: Class, height: (f32, f32), temperature: f32, rainfall: f32) -> LegacyBiome {
    LegacyBiome { id, class, min_height: height.0, max_height: height.1, temperature, rainfall, ..BASE }
}

const fn decor(trees: i32, flowers: i32, grass: i32) -> Decorator {
    Decorator { trees, flowers, grass, ..DEFAULT }
}

const PLAINS_DECOR: Decorator = decor(-999, 4, 10);
const DESERT_DECOR: Decorator = Decorator { trees: -999, dead_bushes: 2, reeds: 50, cacti: 10, ..DEFAULT };
const FOREST_DECOR: Decorator = decor(10, 2, 2);
const TAIGA_DECOR: Decorator = Decorator { trees: 10, grass: 1, mushrooms: 1, ..DEFAULT };
const MEGA_TAIGA_DECOR: Decorator = Decorator { trees: 10, grass: 7, dead_bushes: 1, mushrooms: 3, ..DEFAULT };
const SWAMP_DECOR: Decorator = Decorator { trees: 2, flowers: 1, dead_bushes: 1, mushrooms: 8, reeds: 10, clay: 1, waterlilies: 4, sand: 0, gravel: 0, grass: 5, ..DEFAULT };
const MUSHROOM_DECOR: Decorator = Decorator { trees: -100, flowers: -100, grass: -100, mushrooms: 1, big_mushrooms: 1, ..DEFAULT };
const BEACH_DECOR: Decorator = Decorator { trees: -999, dead_bushes: 0, reeds: 0, cacti: 0, ..DEFAULT };
const JUNGLE_DECOR: Decorator = decor(50, 4, 25);
const JUNGLE_EDGE_DECOR: Decorator = decor(2, 4, 25);
const SAVANNA_DECOR: Decorator = decor(1, 4, 20);
const SAVANNA_M_DECOR: Decorator = decor(2, 2, 5);
const MESA_DECOR: Decorator = Decorator { trees: -999, dead_bushes: 20, reeds: 3, cacti: 5, flowers: 0, ..DEFAULT };
const MESA_FOREST_DECOR: Decorator = Decorator { trees: 5, ..MESA_DECOR };

use Class as C;

const fn ocean(id: i32, height: (f32, f32), temperature: f32, snowy: bool) -> LegacyBiome {
    LegacyBiome { snowy, ..biome(id, C::Ocean, height, temperature, 0.5) }
}

const fn hills(id: i32, height: (f32, f32), trees: bool) -> LegacyBiome {
    LegacyBiome { surface: Surface::Hills { stone: !trees, gravel: false }, style: Style::Hills, decorator: decor(if trees { 3 } else { 0 }, 2, 1), ..biome(id, C::Hills, height, 0.2, 0.3) }
}

const fn forest(id: i32, height: (f32, f32), kind: u8) -> LegacyBiome {
    let (temperature, rainfall) = if kind == 2 { (0.6, 0.6) } else { (0.7, 0.8) };
    let decorator = match kind {
        1 => decor(6, 100, 1),
        3 => decor(-999, 2, 2),
        _ => FOREST_DECOR,
    };
    LegacyBiome { style: Style::Forest { kind }, decorator, ..biome(id, C::Forest, height, temperature, rainfall) }
}

const fn taiga(id: i32, height: (f32, f32), kind: u8, temperature: f32, rainfall: f32, snowy: bool) -> LegacyBiome {
    let mega = kind != 0;
    LegacyBiome {
        snowy,
        surface: if mega { Surface::MegaTaiga } else { Surface::Normal },
        style: Style::Taiga { kind },
        decorator: if mega { MEGA_TAIGA_DECOR } else { TAIGA_DECOR },
        ..biome(id, C::Taiga, height, temperature, rainfall)
    }
}

const fn sandy(id: i32, class: Class, height: (f32, f32), temperature: f32, rainfall: f32, snowy: bool) -> LegacyBiome {
    LegacyBiome { top: blocks::SAND, filler: blocks::SAND, snowy, decorator: BEACH_DECOR, ..biome(id, class, height, temperature, rainfall) }
}

const fn desert(id: i32, height: (f32, f32)) -> LegacyBiome {
    LegacyBiome { top: blocks::SAND, filler: blocks::SAND, style: Style::Desert, decorator: DESERT_DECOR, ..biome(id, C::Desert, height, 2.0, 0.0) }
}

const fn jungle(id: i32, height: (f32, f32), edge: bool) -> LegacyBiome {
    LegacyBiome { style: Style::Jungle { edge }, decorator: if edge { JUNGLE_EDGE_DECOR } else { JUNGLE_DECOR }, ..biome(id, C::Jungle, height, 0.95, if edge { 0.8 } else { 0.9 }) }
}

const fn snow(id: i32, height: (f32, f32), spikes: bool) -> LegacyBiome {
    LegacyBiome { snowy: true, top: if spikes { blocks::SNOW } else { blocks::GRASS_BLOCK }, style: Style::Snow { spikes }, ..biome(id, C::Snow, height, 0.0, 0.5) }
}

const fn savanna(id: i32, height: (f32, f32), temperature: f32) -> LegacyBiome {
    LegacyBiome { style: Style::Savanna, decorator: SAVANNA_DECOR, ..biome(id, C::Savanna, height, temperature, 0.0) }
}

const fn mesa(id: i32, height: (f32, f32), bryce: bool, forest: bool) -> LegacyBiome {
    LegacyBiome {
        top: blocks::RED_SAND,
        filler: blocks::WHITE_TERRACOTTA,
        surface: Surface::Mesa { bryce, forest },
        style: Style::Mesa,
        decorator: if forest { MESA_FOREST_DECOR } else { MESA_DECOR },
        ..biome(id, C::Mesa, height, 2.0, 0.0)
    }
}

/// A plain mutation: a little higher and rougher than its base, otherwise the same.
const fn mutated(base: LegacyBiome) -> LegacyBiome {
    LegacyBiome { id: base.id + 128, min_height: base.min_height + 0.1, max_height: base.max_height + 0.2, style: Style::Mutated(base.id), ..base }
}

const fn with_height(biome: LegacyBiome, height: (f32, f32)) -> LegacyBiome {
    LegacyBiome { min_height: height.0, max_height: height.1, ..biome }
}

const PLAINS: LegacyBiome = LegacyBiome { style: Style::Plains { sunflowers: false }, decorator: PLAINS_DECOR, ..biome(1, C::Plains, LOW_PLAINS, 0.8, 0.4) };
const DESERT: LegacyBiome = desert(2, LOW_PLAINS);
const EXTREME_HILLS: LegacyBiome = hills(3, MID_HILLS, false);
const FOREST: LegacyBiome = forest(4, DEFAULT_HEIGHT, 0);
const TAIGA: LegacyBiome = taiga(5, MID_PLAINS, 0, 0.25, 0.8, false);
const SWAMP: LegacyBiome = LegacyBiome { surface: Surface::Swamp, style: Style::Swamp, decorator: SWAMP_DECOR, ..biome(6, C::Swamp, PARTIALLY_SUBMERGED, 0.8, 0.9) };
const ICE_PLAINS: LegacyBiome = snow(12, LOW_PLAINS, false);
const JUNGLE: LegacyBiome = jungle(21, DEFAULT_HEIGHT, false);
const JUNGLE_EDGE: LegacyBiome = jungle(23, DEFAULT_HEIGHT, true);
const BIRCH_FOREST: LegacyBiome = forest(27, DEFAULT_HEIGHT, 2);
const BIRCH_FOREST_HILLS: LegacyBiome = forest(28, LOW_HILLS, 2);
const ROOFED_FOREST: LegacyBiome = forest(29, DEFAULT_HEIGHT, 3);
const COLD_TAIGA: LegacyBiome = taiga(30, MID_PLAINS, 0, -0.5, 0.4, true);
const MEGA_TAIGA_HILLS: LegacyBiome = taiga(33, LOW_HILLS, 1, 0.3, 0.8, false);
const EXTREME_HILLS_PLUS: LegacyBiome = hills(34, MID_HILLS, true);
const SAVANNA: LegacyBiome = savanna(35, LOW_PLAINS, 1.2);
const SAVANNA_PLATEAU: LegacyBiome = savanna(36, HIGH_PLATEAUS, 1.0);

const fn savanna_mutated(base: LegacyBiome) -> LegacyBiome {
    LegacyBiome {
        id: base.id + 128,
        temperature: (base.temperature + 1.0) * 0.5,
        min_height: base.min_height * 0.5 + 0.3,
        max_height: base.max_height * 0.5 + 1.2,
        surface: Surface::SavannaMutated,
        style: Style::SavannaMutated,
        decorator: SAVANNA_M_DECOR,
        ..base
    }
}

const fn hills_mutated(base: LegacyBiome) -> LegacyBiome {
    //the game makes these as new hills, which keeps the height of the base
    LegacyBiome { id: base.id + 128, surface: Surface::Hills { stone: true, gravel: true }, decorator: decor(0, 2, 1), ..base }
}

#[rustfmt::skip]
static LIST: [LegacyBiome; 61] = [
    ocean(0, OCEANS, 0.5, false),
    PLAINS,
    DESERT,
    EXTREME_HILLS,
    FOREST,
    TAIGA,
    SWAMP,
    biome(7, C::River, SHALLOW_WATERS, 0.5, 0.5),
    biome(8, C::Hell, DEFAULT_HEIGHT, 2.0, 0.0),
    biome(9, C::End, DEFAULT_HEIGHT, 0.5, 0.5),
    ocean(10, OCEANS, 0.0, true),
    LegacyBiome { snowy: true, ..biome(11, C::River, SHALLOW_WATERS, 0.0, 0.5) },
    ICE_PLAINS,
    snow(13, LOW_HILLS, false),
    LegacyBiome { top: blocks::MYCELIUM, style: Style::Mushroom, decorator: MUSHROOM_DECOR, ..biome(14, C::Mushroom, LOW_ISLANDS, 0.9, 1.0) },
    LegacyBiome { top: blocks::MYCELIUM, style: Style::Mushroom, decorator: MUSHROOM_DECOR, ..biome(15, C::Mushroom, SHORES, 0.9, 1.0) },
    sandy(16, C::Beach, SHORES, 0.8, 0.4, false),
    desert(17, LOW_HILLS),
    forest(18, LOW_HILLS, 0),
    taiga(19, LOW_HILLS, 0, 0.25, 0.8, false),
    hills(20, (0.8, 0.3), true),
    JUNGLE,
    jungle(22, LOW_HILLS, false),
    JUNGLE_EDGE,
    ocean(24, DEEP_OCEANS, 0.5, false),
    LegacyBiome { top: blocks::STONE, filler: blocks::STONE, decorator: BEACH_DECOR, ..biome(25, C::StoneBeach, ROCKY_WATERS, 0.2, 0.3) },
    sandy(26, C::Beach, SHORES, 0.05, 0.3, true),
    BIRCH_FOREST,
    BIRCH_FOREST_HILLS,
    ROOFED_FOREST,
    COLD_TAIGA,
    taiga(31, LOW_HILLS, 0, -0.5, 0.4, true),
    taiga(32, MID_PLAINS, 1, 0.3, 0.8, false),
    MEGA_TAIGA_HILLS,
    EXTREME_HILLS_PLUS,
    SAVANNA,
    SAVANNA_PLATEAU,
    mesa(37, DEFAULT_HEIGHT, false, false),
    mesa(38, HIGH_PLATEAUS, false, true),
    mesa(39, HIGH_PLATEAUS, false, false),

    LegacyBiome { id: 129, style: Style::Plains { sunflowers: true }, ..PLAINS },
    mutated(DESERT),
    hills_mutated(EXTREME_HILLS),
    LegacyBiome { id: 132, max_height: FOREST.max_height + 0.2, ..forest(132, DEFAULT_HEIGHT, 1) },
    mutated(TAIGA),
    mutated(SWAMP),
    with_height(LegacyBiome { id: 140, ..snow(140, LOW_PLAINS, true) }, (LOW_PLAINS.0 + 0.1, LOW_PLAINS.1 + 0.1)),
    mutated(JUNGLE),
    mutated(JUNGLE_EDGE),
    LegacyBiome { style: Style::TallBirch, ..mutated(BIRCH_FOREST) },
    LegacyBiome { style: Style::TallBirch, ..mutated(BIRCH_FOREST_HILLS) },
    LegacyBiome { style: Style::RoofedMutated, ..mutated(ROOFED_FOREST) },
    mutated(COLD_TAIGA),
    with_height(taiga(160, MID_PLAINS, 2, 0.25, 0.8, false), MID_PLAINS),
    mutated(MEGA_TAIGA_HILLS),
    hills_mutated(EXTREME_HILLS_PLUS),
    savanna_mutated(SAVANNA),
    savanna_mutated(SAVANNA_PLATEAU),
    mesa(165, DEFAULT_HEIGHT, true, false),
    mesa(166, LOW_HILLS, false, true),
    mesa(167, LOW_HILLS, false, false),
];

static TABLE: std::sync::OnceLock<[Option<&'static LegacyBiome>; 256]> = std::sync::OnceLock::new();

fn table() -> &'static [Option<&'static LegacyBiome>; 256] {
    TABLE.get_or_init(|| {
        let mut table = [None; 256];

        for biome in &LIST {
            table[biome.id as usize] = Some(biome);
        }

        table
    })
}

pub fn get(id: i32) -> Option<&'static LegacyBiome> {
    if (0..256).contains(&id) { table()[id as usize] } else { None }
}

/// The biome, plains for an ID that should never come up.
pub fn of(id: i32) -> &'static LegacyBiome {
    get(id).unwrap_or(&LIST[1])
}

pub fn exists(id: i32) -> bool {
    get(id).is_some()
}

pub fn is_oceanic(id: i32) -> bool {
    matches!(id, id::OCEAN | id::DEEP_OCEAN | id::FROZEN_OCEAN)
}

pub fn is_jungle(id: i32) -> bool {
    get(id).is_some_and(|biome| biome.class == Class::Jungle)
}

pub fn is_mesa(id: i32) -> bool {
    get(id).is_some_and(|biome| biome.class == Class::Mesa)
}

/// The game's "equal or mesa plateau": the same biome, the same class, or both plateaus.
pub fn similar(a: i32, b: i32) -> bool {
    if a == b {
        return true;
    }

    if a == id::MESA_PLATEAU_F || a == id::MESA_PLATEAU {
        return b == id::MESA_PLATEAU_F || b == id::MESA_PLATEAU;
    }

    match (get(a), get(b)) {
        (Some(a), Some(b)) => a.class == b.class,
        _ => false,
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn table_is_complete() {
        assert_eq!(LIST.iter().filter(|biome| biome.id < 128).count(), 40);

        for (index, biome) in LIST.iter().enumerate() {
            assert_eq!(get(biome.id).map(|found| found.id), Some(biome.id), "entry {index}");
        }

        assert!(similar(id::FOREST, id::BIRCH_FOREST_HILLS));
        assert!(!similar(id::FOREST, id::TAIGA));
        assert_eq!(of(140).min_height, 0.125f32 + 0.1);
        assert_eq!(of(163).max_height, 0.05f32 * 0.5 + 1.2);
        assert_eq!(of(131).min_height, 1.0);
    }
}
