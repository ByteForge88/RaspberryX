//! The pre-normal world type: infinite terrain the way Java Edition 1.8 made it, from the
//! same seeds to the same land.

use std::sync::OnceLock;

pub mod biomes;
pub mod carvers;
pub mod decorate;
pub mod layers;
pub mod terrain;
pub mod trees;
pub mod world;

#[cfg(test)]
mod preview;

/// The game's sine, read from a table of 65536 steps. Caves and ore veins bend with it, so
/// the real thing is needed for them to land where they did.
pub fn sin(value: f32) -> f32 {
    table()[((value * 10430.378) as i32 & 65535) as usize]
}

pub fn cos(value: f32) -> f32 {
    table()[((value * 10430.378 + 16384.0) as i32 & 65535) as usize]
}

fn table() -> &'static [f32] {
    static TABLE: OnceLock<Vec<f32>> = OnceLock::new();
    TABLE.get_or_init(|| (0..65536).map(|i| (i as f64 * std::f64::consts::PI * 2.0 / 65536.0).sin() as f32).collect())
}

/// Rounds down to a whole number, the way the game's floor_double does.
pub fn floor(value: f64) -> i32 {
    let int = value as i32;
    if value < int as f64 { int - 1 } else { int }
}

/// The world seed folded in with a chunk position, as the game seeds a chunk's random.
pub fn chunk_seed(chunk_x: i32, chunk_z: i32) -> i64 {
    (chunk_x as i64).wrapping_mul(341873128712).wrapping_add((chunk_z as i64).wrapping_mul(132897987541))
}

use std::collections::{HashMap, VecDeque};
use std::sync::{Arc, Mutex};

use self::terrain::{temperature, Terrain, TerrainGenerator, HEIGHT, SEA_LEVEL};
use self::world::{blocks_movement, is_liquid, Merged, Region, View};
use crate::chunk::{Chunk, Storage, SubChunk, MIN_SUBCHUNK};
use crate::generator::blocks::{self, Block};
use crate::random::JavaRandom;

/// How many chunks of bare terrain stay around for their neighbours: each chunk needs the
/// eight around it to be decorated.
const CACHE_SIZE: usize = 768;

type Cell = Arc<OnceLock<Arc<Terrain>>>;

#[derive(Default)]
struct Cache {
    terrain: HashMap<(i32, i32), Cell>,
    order: VecDeque<(i32, i32)>,
}

pub struct PreNormal {
    pub seed: i64,
    generator: TerrainGenerator,
    cache: Mutex<Cache>,
}

/// A finished chunk's blocks, (x * 16 + z) * 256 + y, and its biomes, x + z * 16.
pub struct Column {
    pub blocks: Vec<Block>,
    pub biomes: [u8; 256],
}

impl PreNormal {
    pub fn new(seed: i64) -> Self {
        PreNormal { seed, generator: TerrainGenerator::new(seed), cache: Mutex::new(Cache::default()) }
    }

    /// The bare terrain of a chunk, made once and shared while it stays in the cache.
    pub fn terrain(&self, chunk_x: i32, chunk_z: i32) -> Arc<Terrain> {
        let cell = {
            let mut cache = self.cache.lock().unwrap_or_else(|poisoned| poisoned.into_inner());

            match cache.terrain.get(&(chunk_x, chunk_z)) {
                Some(cell) => Arc::clone(cell),
                None => {
                    let cell: Cell = Arc::new(OnceLock::new());
                    cache.terrain.insert((chunk_x, chunk_z), Arc::clone(&cell));
                    cache.order.push_back((chunk_x, chunk_z));

                    while cache.order.len() > CACHE_SIZE {
                        if let Some(old) = cache.order.pop_front() {
                            cache.terrain.remove(&old);
                        }
                    }

                    cell
                }
            }
        };

        Arc::clone(cell.get_or_init(|| Arc::new(self.generator.generate(chunk_x, chunk_z))))
    }

    /// A chunk with its decoration and the decoration that reaches into it from the north
    /// and west, then frozen where it is cold.
    pub fn column(&self, chunk_x: i32, chunk_z: i32) -> Column {
        let mut terrains = Vec::with_capacity(9);

        for dx in -1..=1 {
            for dz in -1..=1 {
                terrains.push(self.terrain(chunk_x + dx, chunk_z + dz));
            }
        }

        let refs: Vec<&Terrain> = terrains.iter().map(|terrain| terrain.as_ref()).collect();
        let region = Region::new(chunk_x, chunk_z, &refs);
        let mut merged = Merged::new(&region);
        let mut view = View::new(&region);

        for (px, pz) in [(chunk_x - 1, chunk_z - 1), (chunk_x - 1, chunk_z), (chunk_x, chunk_z - 1), (chunk_x, chunk_z)] {
            decorate::populate(&mut view, self.seed, px, pz);
            view.merge_into(&mut merged);
        }

        let (x0, z0) = (chunk_x * 16, chunk_z * 16);

        for x in x0..x0 + 16 {
            for z in z0..z0 + 16 {
                freeze(&region, &mut merged, x, z);
            }
        }

        let middle = &terrains[4];
        let mut blocks = vec![blocks::AIR; 16 * 16 * HEIGHT as usize];

        for x in 0..16 {
            for z in 0..16 {
                for y in 0..HEIGHT {
                    blocks[terrain::index(x, y, z)] = merged.get(&region, x0 + x, y, z0 + z);
                }
            }
        }

        Column { blocks, biomes: middle.biomes }
    }

    pub fn chunk(&self, chunk_x: i32, chunk_z: i32, states: &[u32]) -> Chunk {
        to_chunk(&self.column(chunk_x, chunk_z), states)
    }

    /// Where players first appear: a spot in a land biome near 0, 0 with grass on top, found
    /// the way the game looks for it.
    pub fn spawn(&self) -> (i32, i32, i32) {
        let mut random = JavaRandom::new(self.seed);
        let allowed = [biomes::id::FOREST, biomes::id::PLAINS, biomes::id::TAIGA, biomes::id::TAIGA_HILLS, biomes::id::FOREST_HILLS, biomes::id::JUNGLE, biomes::id::JUNGLE_HILLS];
        let (from_x, from_z) = (-256 >> 2, -256 >> 2);
        let (to_x, to_z) = (256 >> 2, 256 >> 2);
        let (width, length) = (to_x - from_x + 1, to_z - from_z + 1);
        let area = self.generator.layers.quarter(from_x, from_z, width, length);
        let mut found: Option<(i32, i32)> = None;
        let mut count = 0;

        for (i, biome) in area.iter().enumerate() {
            let i = i as i32;

            if allowed.contains(biome) && (found.is_none() || random.next_int_bounded(count + 1) == 0) {
                found = Some(((from_x + i % width) << 2, (from_z + i / width) << 2));
                count += 1;
            }
        }

        let (mut x, mut z) = found.unwrap_or((0, 0));
        let mut tries = 0;

        while !self.grass_on_top(x, z) {
            x += random.next_int_bounded(64) - random.next_int_bounded(64);
            z += random.next_int_bounded(64) - random.next_int_bounded(64);
            tries += 1;

            if tries == 1000 {
                break;
            }
        }

        let column = self.column(x >> 4, z >> 4);
        let (lx, lz) = (x & 15, z & 15);
        let mut y = HEIGHT - 1;

        while y > 0 {
            let block = column.blocks[terrain::index(lx, y, lz)];

            if (blocks_movement(block) && !blocks::is_leaves(block)) || is_liquid(block) {
                break;
            }

            y -= 1;
        }

        (x, y + 1, z)
    }

    /// The game's spawn test: up from the sea, the first block with air over it is grass.
    fn grass_on_top(&self, x: i32, z: i32) -> bool {
        let terrain = self.terrain(x >> 4, z >> 4);
        let (lx, lz) = (x & 15, z & 15);
        let mut y = SEA_LEVEL;

        while y + 1 < HEIGHT && terrain.get(lx, y + 1, lz) != blocks::AIR {
            y += 1;
        }

        terrain.get(lx, y, lz) == blocks::GRASS_BLOCK
    }
}

/// Ice on still water and snow on the ground, where it is cold enough.
fn freeze(region: &Region, merged: &mut Merged, x: i32, z: i32) {
    let biome = region.biome(x, z);
    let mut y = HEIGHT - 1;

    while y >= 0 {
        let block = merged.get(region, x, y, z);

        if blocks_movement(block) || is_liquid(block) {
            break;
        }

        y -= 1;
    }

    let top = y + 1;

    if top >= 1 && merged.get(region, x, top - 1, z) == blocks::WATER && temperature(biome, x, top - 1, z) <= 0.15 {
        merged.set(region, x, top - 1, z, blocks::ICE);
    }

    if top < HEIGHT && merged.get(region, x, top, z) == blocks::AIR && temperature(biome, x, top, z) <= 0.15 {
        let below = merged.get(region, x, top - 1, z);
        let holds = blocks::is_leaves(below) || (world::is_full(below) && !matches!(below, blocks::ICE | blocks::PACKED_ICE));

        if holds {
            merged.set(region, x, top, z, blocks::SNOW_LAYER);
        }
    }
}

fn to_chunk(column: &Column, states: &[u32]) -> Chunk {
    let air = states[blocks::AIR as usize];
    let mut chunk = Chunk::new(air, biomes::id::PLAINS as u32);

    let mut biome_palette: Vec<u32> = Vec::new();
    let mut biome_offsets = vec![0u16; 4096];

    for x in 0..16 {
        for z in 0..16 {
            let id = column.biomes[x + z * 16] as u32;
            let offset = biome_palette.iter().position(|entry| *entry == id).unwrap_or_else(|| {
                biome_palette.push(id);
                biome_palette.len() - 1
            });

            for y in 0..16 {
                biome_offsets[crate::chunk::index(x, y, z)] = offset as u16;
            }
        }
    }

    let biome_storage = if biome_palette.len() == 1 {
        Storage { palette: biome_palette, indices: None }
    } else {
        Storage { palette: biome_palette, indices: Some(biome_offsets.into_boxed_slice()) }
    };

    for sub in 0..chunk.subchunks.len() {
        let bottom = (sub as i32 + MIN_SUBCHUNK) * 16;
        let mut layer = Storage::new(air);

        if (0..HEIGHT).contains(&bottom) {
            for x in 0..16 {
                for z in 0..16 {
                    for y in 0..16 {
                        let block = column.blocks[terrain::index(x, bottom + y, z)];

                        if block != blocks::AIR {
                            layer.set(crate::chunk::index(x as usize, y as usize, z as usize), states[block as usize]);
                        }
                    }
                }
            }
        }

        chunk.subchunks[sub] = Arc::new(SubChunk { layers: vec![layer], biomes: biome_storage.clone() });
    }

    chunk
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn chunks_do_not_depend_on_order() {
        let fresh = PreNormal::new(42).column(3, -2);
        let warm = PreNormal::new(42);

        for (x, z) in [(4, -2), (3, -1), (2, -3), (3, -2)] {
            warm.column(x, z);
        }

        assert!(fresh.blocks == warm.column(3, -2).blocks);
    }

    #[test]
    fn makes_land_and_sea() {
        let generator = PreNormal::new(1);
        let column = generator.column(10, 16);
        let bedrock = (0..16).all(|x| column.blocks[terrain::index(x, 0, 0)] == blocks::BEDROCK);
        assert!(bedrock);
        assert!(column.blocks.iter().filter(|&&block| block == blocks::STONE).count() > 10000);

        let (x, y, z) = generator.spawn();
        assert!((-512..=512).contains(&x) && (-512..=512).contains(&z), "{x} {z}");
        assert!((SEA_LEVEL..HEIGHT).contains(&y), "{y}");
    }
}
