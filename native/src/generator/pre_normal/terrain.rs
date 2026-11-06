//! The shape of the land and its top layers, chunk by chunk, as 1.8 makes them before any
//! decoration: the density grid, the biome surface, then caves and ravines.

use std::sync::OnceLock;

use super::biomes::{self, LegacyBiome, Surface};
use super::layers::Layers;
use super::{carvers, chunk_seed};
use crate::generator::blocks::{self, Block};
use crate::noise::{LegacySimplexOctaves, OctavePerlin};
use crate::random::JavaRandom;

pub const HEIGHT: i32 = 256;
pub const SEA_LEVEL: i32 = 63;

/// The settings of the default world type, floats as the game keeps them.
const COORDINATE_SCALE: f32 = 684.412;
const HEIGHT_SCALE: f32 = 684.412;
const MAIN_SCALE_X: f32 = 80.0;
const MAIN_SCALE_Y: f32 = 160.0;
const MAIN_SCALE_Z: f32 = 80.0;
const DEPTH_SCALE: f32 = 200.0;
const LIMIT_SCALE: f32 = 512.0;
const BASE_SIZE: f32 = 8.5;
const STRETCH_Y: f32 = 12.0;

/// One chunk of terrain: blocks indexed (x * 16 + z) * 256 + y, biomes x + z * 16.
#[derive(Clone)]
pub struct Terrain {
    pub blocks: Vec<Block>,
    pub biomes: [u8; 256],
}

#[inline]
pub fn index(x: i32, y: i32, z: i32) -> usize {
    ((x * 16 + z) * HEIGHT + y) as usize
}

impl Terrain {
    pub fn get(&self, x: i32, y: i32, z: i32) -> Block {
        self.blocks[index(x, y, z)]
    }

    pub fn set(&mut self, x: i32, y: i32, z: i32, block: Block) {
        self.blocks[index(x, y, z)] = block;
    }

    pub fn biome(&self, x: i32, z: i32) -> &'static LegacyBiome {
        biomes::of(self.biomes[(x + z * 16) as usize] as i32)
    }
}

/// Noises that do not change with the seed.
pub struct Fixed {
    pub temperature: LegacySimplexOctaves,
    pub grass_color: LegacySimplexOctaves,
}

pub fn fixed() -> &'static Fixed {
    static FIXED: OnceLock<Fixed> = OnceLock::new();
    FIXED.get_or_init(|| Fixed {
        temperature: LegacySimplexOctaves::new(&mut JavaRandom::new(1234), 1),
        grass_color: LegacySimplexOctaves::new(&mut JavaRandom::new(2345), 1),
    })
}

/// A biome's temperature at a spot: colder the higher above 64 it is, with some noise.
pub fn temperature(biome: &LegacyBiome, x: i32, y: i32, z: i32) -> f32 {
    if y > 64 {
        let noise = (fixed().temperature.sample(x as f64 / 8.0, z as f64 / 8.0) * 4.0) as f32;
        biome.temperature - (noise + y as f32 - 64.0) * 0.05 / 30.0
    } else {
        biome.temperature
    }
}

/// The colour bands of mesas and the noise that bends them, from the seed.
struct Mesa {
    bands: [Block; 64],
    bend: LegacySimplexOctaves,
    pillars: LegacySimplexOctaves,
    pillar_tops: LegacySimplexOctaves,
}

impl Mesa {
    fn new(seed: i64) -> Self {
        let mut bands = [blocks::TERRACOTTA; 64];
        let mut random = JavaRandom::new(seed);
        let bend = LegacySimplexOctaves::new(&mut random, 1);

        let mut y = 0;
        while y < 64 {
            y += random.next_int_bounded(5) + 1;
            if y < 64 {
                bands[y as usize] = blocks::ORANGE_TERRACOTTA;
            }
            y += 1;
        }

        for (color, extra) in [(blocks::YELLOW_TERRACOTTA, 1), (blocks::BROWN_TERRACOTTA, 2), (blocks::RED_TERRACOTTA, 1)] {
            let count = random.next_int_bounded(4) + 2;

            for _ in 0..count {
                let length = random.next_int_bounded(3) + extra;
                let start = random.next_int_bounded(64);
                let mut i = 0;

                while start + i < 64 && i < length {
                    bands[(start + i) as usize] = color;
                    i += 1;
                }
            }
        }

        let count = random.next_int_bounded(3) + 3;
        let mut start = 0;

        for _ in 0..count {
            start += random.next_int_bounded(16) + 4;

            if start < 64 {
                bands[start as usize] = blocks::WHITE_TERRACOTTA;

                if start > 1 && random.next_bool() {
                    bands[start as usize - 1] = blocks::LIGHT_GRAY_TERRACOTTA;
                }

                if start < 63 && random.next_bool() {
                    bands[start as usize + 1] = blocks::LIGHT_GRAY_TERRACOTTA;
                }
            }
        }

        //the game seeds the pillar noise with the seed it had before, which starts at 0
        let mut random = JavaRandom::new(0);
        let pillars = LegacySimplexOctaves::new(&mut random, 4);
        let pillar_tops = LegacySimplexOctaves::new(&mut random, 1);

        Mesa { bands, bend, pillars, pillar_tops }
    }

    fn band(&self, x: i32, y: i32) -> Block {
        //the game reads x twice here
        let shift = (self.bend.sample(x as f64 / 512.0, x as f64 / 512.0) * 2.0).round() as i32;
        self.bands[((y + shift + 64) % 64) as usize]
    }
}

pub struct TerrainGenerator {
    pub seed: i64,
    pub layers: Layers,
    min_limit: OctavePerlin,
    max_limit: OctavePerlin,
    main: OctavePerlin,
    surface: LegacySimplexOctaves,
    depth: OctavePerlin,
    parabolic: [f32; 25],
    mesa: Mesa,
}

impl TerrainGenerator {
    pub fn new(seed: i64) -> Self {
        let mut random = JavaRandom::new(seed);
        let min_limit = OctavePerlin::new(&mut random, 16);
        let max_limit = OctavePerlin::new(&mut random, 16);
        let main = OctavePerlin::new(&mut random, 8);
        let surface = LegacySimplexOctaves::new(&mut random, 4);
        let _scale = OctavePerlin::new(&mut random, 10);
        let depth = OctavePerlin::new(&mut random, 16);

        let mut parabolic = [0.0f32; 25];

        for j in -2i32..=2 {
            for k in -2i32..=2 {
                parabolic[(j + 2 + (k + 2) * 5) as usize] = 10.0 / (((j * j + k * k) as f32 + 0.2) as f64).sqrt() as f32;
            }
        }

        TerrainGenerator { seed, layers: Layers::new(seed), min_limit, max_limit, main, surface, depth, parabolic, mesa: Mesa::new(seed) }
    }

    /// The 5 x 33 x 5 density grid of a chunk, index (x * 5 + z) * 33 + y.
    fn density(&self, chunk_x: i32, chunk_z: i32, generation_biomes: &[i32]) -> Vec<f64> {
        let (x, z) = (chunk_x * 4, chunk_z * 4);
        let depth = self.depth.grid_wrapped(x, 10, z, 5, 1, 5, DEPTH_SCALE as f64, 1.0, DEPTH_SCALE as f64);
        let main = self.main.grid_wrapped(x, 0, z, 5, 33, 5, (COORDINATE_SCALE / MAIN_SCALE_X) as f64, (HEIGHT_SCALE / MAIN_SCALE_Y) as f64, (COORDINATE_SCALE / MAIN_SCALE_Z) as f64);
        let min = self.min_limit.grid_wrapped(x, 0, z, 5, 33, 5, COORDINATE_SCALE as f64, HEIGHT_SCALE as f64, COORDINATE_SCALE as f64);
        let max = self.max_limit.grid_wrapped(x, 0, z, 5, 33, 5, COORDINATE_SCALE as f64, HEIGHT_SCALE as f64, COORDINATE_SCALE as f64);

        let mut out = vec![0.0; 825];
        let mut i = 0;
        let mut j = 0;

        for k in 0..5 {
            for l in 0..5 {
                let mut variation = 0.0f32;
                let mut height = 0.0f32;
                let mut weights = 0.0f32;
                let center = biomes::of(generation_biomes[(k + 2 + (l + 2) * 10) as usize]);

                for i1 in -2..=2 {
                    for j1 in -2..=2 {
                        let near = biomes::of(generation_biomes[(k + i1 + 2 + (l + j1 + 2) * 10) as usize]);
                        let root = near.min_height;
                        let scale = near.max_height;
                        let mut weight = self.parabolic[(i1 + 2 + (j1 + 2) * 5) as usize] / (root + 2.0);

                        if near.min_height > center.min_height {
                            weight /= 2.0;
                        }

                        variation += scale * weight;
                        height += root * weight;
                        weights += weight;
                    }
                }

                variation /= weights;
                height /= weights;
                variation = variation * 0.9 + 0.1;
                height = (height * 4.0 - 1.0) / 8.0;

                let mut noise = depth[j] / 8000.0;
                if noise < 0.0 {
                    noise = -noise * 0.3;
                }
                noise = noise * 3.0 - 2.0;
                if noise < 0.0 {
                    noise /= 2.0;
                    if noise < -1.0 {
                        noise = -1.0;
                    }
                    noise /= 1.4;
                    noise /= 2.0;
                } else {
                    if noise > 1.0 {
                        noise = 1.0;
                    }
                    noise /= 8.0;
                }
                j += 1;

                let mut base = height as f64;
                let spread = variation as f64;
                base += noise * 0.2;
                base = base * BASE_SIZE as f64 / 8.0;
                let level = BASE_SIZE as f64 + base * 4.0;

                for y in 0..33 {
                    let mut falloff = (y as f64 - level) * STRETCH_Y as f64 * 128.0 / 256.0 / spread;
                    if falloff < 0.0 {
                        falloff *= 4.0;
                    }

                    let lower = min[i] / LIMIT_SCALE as f64;
                    let upper = max[i] / LIMIT_SCALE as f64;
                    let mix = (main[i] / 10.0 + 1.0) / 2.0;
                    let clamped = if mix < 0.0 { lower } else if mix > 1.0 { upper } else { lower + (upper - lower) * mix };
                    let mut value = clamped - falloff;

                    if y > 29 {
                        let fade = ((y - 29) as f32 / 3.0) as f64;
                        value = value * (1.0 - fade) + -10.0 * fade;
                    }

                    out[i] = value;
                    i += 1;
                }
            }
        }

        out
    }

    /// Stone and water from the density grid.
    fn shape(&self, terrain: &mut Terrain, chunk_x: i32, chunk_z: i32) {
        let generation_biomes = self.layers.quarter(chunk_x * 4 - 2, chunk_z * 4 - 2, 10, 10);
        let density = self.density(chunk_x, chunk_z, &generation_biomes);

        for k in 0..4 {
            let l = k * 5;
            let i1 = (k + 1) * 5;

            for j1 in 0..4 {
                let k1 = (l + j1) * 33;
                let l1 = (l + j1 + 1) * 33;
                let i2 = (i1 + j1) * 33;
                let j2 = (i1 + j1 + 1) * 33;

                for k2 in 0..32 {
                    let mut d1 = density[k1 + k2];
                    let mut d2 = density[l1 + k2];
                    let mut d3 = density[i2 + k2];
                    let mut d4 = density[j2 + k2];
                    let d5 = (density[k1 + k2 + 1] - d1) * 0.125;
                    let d6 = (density[l1 + k2 + 1] - d2) * 0.125;
                    let d7 = (density[i2 + k2 + 1] - d3) * 0.125;
                    let d8 = (density[j2 + k2 + 1] - d4) * 0.125;

                    for l2 in 0..8 {
                        let mut d10 = d1;
                        let mut d11 = d2;
                        let d12 = (d3 - d1) * 0.25;
                        let d13 = (d4 - d2) * 0.25;

                        for i3 in 0..4 {
                            let d16 = (d11 - d10) * 0.25;
                            let mut d15 = d10 - d16;

                            for j3 in 0..4 {
                                let y = (k2 * 8 + l2) as i32;
                                d15 += d16;
                                let block = if d15 > 0.0 {
                                    blocks::STONE
                                } else if y < SEA_LEVEL {
                                    blocks::WATER
                                } else {
                                    continue;
                                };
                                terrain.set((k * 4 + i3) as i32, y, (j1 * 4 + j3) as i32, block);
                            }

                            d10 += d12;
                            d11 += d13;
                        }

                        d1 += d5;
                        d2 += d6;
                        d3 += d7;
                        d4 += d8;
                    }
                }
            }
        }
    }

    /// The top layers of every column, biome by biome.
    fn surface(&self, terrain: &mut Terrain, random: &mut JavaRandom, chunk_x: i32, chunk_z: i32) {
        let noise = self.surface.grid((chunk_x * 16) as f64, (chunk_z * 16) as f64, 16, 16, 0.0625, 0.0625, 1.0);

        //the game walks the columns z first and swaps x and z on the way in, so the
        //coordinates a biome is handed are the column's with x and z traded
        for lz in 0..16 {
            for lx in 0..16 {
                let biome = terrain.biome(lx, lz);
                let value = noise[(lx + lz * 16) as usize];
                let swapped_x = chunk_x * 16 + lz;
                let swapped_z = chunk_z * 16 + lx;

                match biome.surface {
                    Surface::Normal => column(terrain, random, lx, lz, biome, Some(biome.top), biome.filler, value),
                    Surface::Hills { stone, gravel } => {
                        let (top, filler) = if gravel && (value < -1.0 || value > 2.0) {
                            (blocks::GRAVEL, blocks::GRAVEL)
                        } else if stone && value > 1.0 {
                            (blocks::STONE, blocks::STONE)
                        } else {
                            (blocks::GRASS_BLOCK, blocks::DIRT)
                        };
                        column(terrain, random, lx, lz, biome, Some(top), filler, value);
                    }
                    Surface::MegaTaiga => {
                        let top = if value > 1.75 {
                            blocks::COARSE_DIRT
                        } else if value > -0.95 {
                            blocks::PODZOL
                        } else {
                            blocks::GRASS_BLOCK
                        };
                        column(terrain, random, lx, lz, biome, Some(top), blocks::DIRT, value);
                    }
                    Surface::SavannaMutated => {
                        let (top, filler) = if value > 1.75 {
                            (blocks::STONE, blocks::STONE)
                        } else if value > -0.5 {
                            (blocks::COARSE_DIRT, blocks::DIRT)
                        } else {
                            (blocks::GRASS_BLOCK, blocks::DIRT)
                        };
                        column(terrain, random, lx, lz, biome, Some(top), filler, value);
                    }
                    Surface::Swamp => {
                        let puddle = fixed().grass_color.sample(swapped_x as f64 * 0.25, swapped_z as f64 * 0.25);

                        if puddle > 0.0 {
                            for y in (0..HEIGHT).rev() {
                                let block = terrain.get(lx, y, lz);

                                if block != blocks::AIR {
                                    if y == 62 && block != blocks::WATER {
                                        terrain.set(lx, y, lz, blocks::WATER);

                                        if puddle < 0.12 {
                                            terrain.set(lx, y + 1, lz, blocks::LILY_PAD);
                                        }
                                    }
                                    break;
                                }
                            }
                        }

                        column(terrain, random, lx, lz, biome, Some(biome.top), biome.filler, value);
                    }
                    Surface::Mesa { bryce, forest } => self.mesa_column(terrain, random, lx, lz, chunk_x * 16 + lx, chunk_z * 16 + lz, swapped_x, bryce, forest, value),
                }
            }
        }
    }

    #[allow(clippy::too_many_arguments)]
    fn mesa_column(&self, terrain: &mut Terrain, random: &mut JavaRandom, lx: i32, lz: i32, world_x: i32, world_z: i32, swapped_x: i32, bryce: bool, forest: bool, value: f64) {
        let mut pillar = 0.0;

        if bryce {
            let strength = value.abs().min(self.mesa.pillars.sample(world_x as f64 * 0.25, world_z as f64 * 0.25));

            if strength > 0.0 {
                let top = self.mesa.pillar_tops.sample(world_x as f64 * 0.001953125, world_z as f64 * 0.001953125).abs();
                pillar = strength * strength * 2.5;
                let limit = (top * 50.0).ceil() + 14.0;

                if pillar > limit {
                    pillar = limit;
                }

                pillar += 64.0;
            }
        }

        let mut top: Option<Block> = Some(blocks::WHITE_TERRACOTTA);
        let mut filler = blocks::WHITE_TERRACOTTA;
        let depth = (value / 3.0 + 3.0 + random.next_double() * 0.25) as i32;
        let banded = (value / 3.0 * std::f64::consts::PI).cos() > 0.0;
        let mut left = -1;
        let mut on_sand = false;

        for y in (0..HEIGHT).rev() {
            if terrain.get(lx, y, lz) == blocks::AIR && y < pillar as i32 {
                terrain.set(lx, y, lz, blocks::STONE);
            }

            if y <= random.next_int_bounded(5) {
                terrain.set(lx, y, lz, blocks::BEDROCK);
                continue;
            }

            let block = terrain.get(lx, y, lz);

            if block == blocks::AIR {
                left = -1;
            } else if block == blocks::STONE {
                if left == -1 {
                    on_sand = false;

                    if depth <= 0 {
                        top = None;
                        filler = blocks::STONE;
                    } else if y >= SEA_LEVEL - 4 && y <= SEA_LEVEL + 1 {
                        top = Some(blocks::WHITE_TERRACOTTA);
                        filler = blocks::WHITE_TERRACOTTA;
                    }

                    if y < SEA_LEVEL && top.is_none_or(|block| block == blocks::AIR) {
                        top = Some(blocks::WATER);
                    }

                    left = depth + (y - SEA_LEVEL).max(0);

                    if y < SEA_LEVEL - 1 {
                        terrain.set(lx, y, lz, if filler == blocks::WHITE_TERRACOTTA { blocks::ORANGE_TERRACOTTA } else { filler });
                    } else if forest && y > 86 + depth * 2 {
                        terrain.set(lx, y, lz, if banded { blocks::COARSE_DIRT } else { blocks::GRASS_BLOCK });
                    } else if y <= SEA_LEVEL + 3 + depth {
                        terrain.set(lx, y, lz, blocks::RED_SAND);
                        on_sand = true;
                    } else {
                        let block = if (64..=127).contains(&y) {
                            if banded { blocks::TERRACOTTA } else { self.mesa.band(swapped_x, y) }
                        } else {
                            blocks::ORANGE_TERRACOTTA
                        };
                        terrain.set(lx, y, lz, block);
                    }
                } else if left > 0 {
                    left -= 1;
                    terrain.set(lx, y, lz, if on_sand { blocks::ORANGE_TERRACOTTA } else { self.mesa.band(swapped_x, y) });
                }
            }
        }
    }

    /// A chunk as it is before decoration.
    pub fn generate(&self, chunk_x: i32, chunk_z: i32) -> Terrain {
        let mut terrain = Terrain { blocks: vec![blocks::AIR; 16 * 16 * HEIGHT as usize], biomes: [0; 256] };
        let mut random = JavaRandom::new(chunk_seed(chunk_x, chunk_z));

        self.shape(&mut terrain, chunk_x, chunk_z);

        for (slot, id) in terrain.biomes.iter_mut().zip(self.layers.full(chunk_x * 16, chunk_z * 16, 16, 16)) {
            *slot = id as u8;
        }

        self.surface(&mut terrain, &mut random, chunk_x, chunk_z);
        carvers::caves(&mut terrain, self.seed, chunk_x, chunk_z);
        carvers::ravines(&mut terrain, self.seed, chunk_x, chunk_z);

        terrain
    }
}

/// The game's usual column: `depth` blocks of filler under the top, sandstone under sand,
/// gravel deep under water and bedrock at the bottom.
#[allow(clippy::too_many_arguments)]
fn column(terrain: &mut Terrain, random: &mut JavaRandom, lx: i32, lz: i32, biome: &LegacyBiome, biome_top: Option<Block>, biome_filler: Block, value: f64) {
    let mut top = biome_top;
    let mut filler = biome_filler;
    let mut left = -1;
    let depth = (value / 3.0 + 3.0 + random.next_double() * 0.25) as i32;

    for y in (0..HEIGHT).rev() {
        if y <= random.next_int_bounded(5) {
            terrain.set(lx, y, lz, blocks::BEDROCK);
            continue;
        }

        let block = terrain.get(lx, y, lz);

        if block == blocks::AIR {
            left = -1;
        } else if block == blocks::STONE {
            if left == -1 {
                if depth <= 0 {
                    top = None;
                    filler = blocks::STONE;
                } else if y >= SEA_LEVEL - 4 && y <= SEA_LEVEL + 1 {
                    top = biome_top;
                    filler = biome_filler;
                }

                if y < SEA_LEVEL && top.is_none_or(|block| block == blocks::AIR) {
                    top = Some(if biome.temperature < 0.15 { blocks::ICE } else { blocks::WATER });
                }

                left = depth;

                if y >= SEA_LEVEL - 1 {
                    //no top at all leaves the game with air
                    terrain.set(lx, y, lz, top.unwrap_or(blocks::AIR));
                } else if y < SEA_LEVEL - 7 - depth {
                    top = None;
                    filler = blocks::STONE;
                    terrain.set(lx, y, lz, blocks::GRAVEL);
                } else {
                    terrain.set(lx, y, lz, filler);
                }
            } else if left > 0 {
                left -= 1;
                terrain.set(lx, y, lz, filler);

                if left == 0 && (filler == blocks::SAND || filler == blocks::RED_SAND) {
                    left = random.next_int_bounded(4) + (y - 63).max(0);
                    filler = if filler == blocks::RED_SAND { blocks::RED_SANDSTONE } else { blocks::SANDSTONE };
                }
            }
        }
    }
}
