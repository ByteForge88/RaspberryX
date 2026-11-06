//! The Old world type: a finite world of 256 x 256 blocks and 128 high, like the first Pocket
//! Edition worlds. Its terrain is the classic generator's, with the biomes of today mixed into
//! the old climates. Barriers stand around the edge, and bedrock is at the bottom, so nobody
//! falls out of the world.
//!
//! The world is small enough to make in one go: the terrain of each chunk on its own thread,
//! then everything that grows on top over the whole world, so nothing gets cut at chunk edges.

use std::f64::consts::PI;
use std::sync::Arc;
use std::thread;

use super::blocks::{self, Block};
use super::features::{self, Canvas};
use crate::biome::{self, Biome, Ground, Tree};
use crate::chunk::{Chunk, Storage, SubChunk, MIN_SUBCHUNK};
use crate::noise::{OctavePerlin, OctaveSimplex};
use crate::random::JavaRandom;

pub const SIZE_CHUNKS: i32 = 16;
pub const SIZE: i32 = SIZE_CHUNKS * 16;
pub const HEIGHT: i32 = 128;
pub const SEA_LEVEL: i32 = 64;

/// how far a cave may reach into other chunks, in chunks
const CAVE_RANGE: i32 = 8;

const TERRACOTTA_BANDS: [Block; 12] = [
    blocks::TERRACOTTA, blocks::ORANGE_TERRACOTTA, blocks::TERRACOTTA, blocks::YELLOW_TERRACOTTA, blocks::BROWN_TERRACOTTA, blocks::TERRACOTTA,
    blocks::RED_TERRACOTTA, blocks::TERRACOTTA, blocks::WHITE_TERRACOTTA, blocks::ORANGE_TERRACOTTA, blocks::LIGHT_GRAY_TERRACOTTA, blocks::TERRACOTTA,
];

/// The whole world while it is made: blocks x, then z, then y, and a biome per column.
struct World {
    blocks: Vec<Block>,
    biomes: Vec<&'static Biome>,
}

#[inline]
fn index(x: i32, y: i32, z: i32) -> usize {
    ((x * SIZE + z) * HEIGHT + y) as usize
}

impl Canvas for World {
    fn get(&self, x: i32, y: i32, z: i32) -> Block {
        if !(0..SIZE).contains(&x) || !(0..SIZE).contains(&z) || !(0..HEIGHT).contains(&y) {
            //outside counts as solid so features do not spill over the edge
            return if (0..HEIGHT).contains(&y) { blocks::BARRIER } else { blocks::AIR };
        }

        self.blocks[index(x, y, z)]
    }

    fn set(&mut self, x: i32, y: i32, z: i32, block: Block) {
        if (0..SIZE).contains(&x) && (0..SIZE).contains(&z) && (0..HEIGHT).contains(&y) {
            self.blocks[index(x, y, z)] = block;
        }
    }

    fn height(&self) -> i32 {
        HEIGHT
    }
}

impl World {
    fn biome(&self, x: i32, z: i32) -> &'static Biome {
        self.biomes[(x.clamp(0, SIZE - 1) * SIZE + z.clamp(0, SIZE - 1)) as usize]
    }
}

/// The noise of the classic generator, made from the seed in its order.
struct Noises {
    min_limit: OctavePerlin,
    max_limit: OctavePerlin,
    main: OctavePerlin,
    beach: OctavePerlin,
    surface: OctavePerlin,
    scale: OctavePerlin,
    depth: OctavePerlin,
    trees: OctavePerlin,
    temperature: OctaveSimplex,
    rainfall: OctaveSimplex,
    climate_detail: OctaveSimplex,
    /// bends the edges of the patches the modern biomes come in
    warp_x: OctavePerlin,
    warp_z: OctavePerlin,
    ground: OctavePerlin,
    seed: i64,
}

/// how wide the patches of one modern biome are, about
const VARIANT_CELL: f64 = 56.0;

impl Noises {
    fn new(seed: i64) -> Self {
        let mut random = JavaRandom::new(seed);

        Noises {
            min_limit: OctavePerlin::new(&mut random, 16),
            max_limit: OctavePerlin::new(&mut random, 16),
            main: OctavePerlin::new(&mut random, 8),
            beach: OctavePerlin::new(&mut random, 4),
            surface: OctavePerlin::new(&mut random, 4),
            scale: OctavePerlin::new(&mut random, 10),
            depth: OctavePerlin::new(&mut random, 16),
            trees: OctavePerlin::new(&mut random, 8),
            temperature: OctaveSimplex::new(&mut JavaRandom::new(seed.wrapping_mul(9871)), 4),
            rainfall: OctaveSimplex::new(&mut JavaRandom::new(seed.wrapping_mul(39811)), 4),
            climate_detail: OctaveSimplex::new(&mut JavaRandom::new(seed.wrapping_mul(0x84a59)), 2),
            warp_x: OctavePerlin::new(&mut JavaRandom::new(seed.wrapping_mul(0x2f8f_e6c1)), 3),
            warp_z: OctavePerlin::new(&mut JavaRandom::new(seed.wrapping_mul(0x1b87_3593)), 3),
            ground: OctavePerlin::new(&mut JavaRandom::new(seed ^ 0x51ed_270b), 3),
            seed,
        }
    }

    /// Temperature and rainfall (0 to 1) and the biome for each column of a 16 x 16 area,
    /// laid out x then z.
    fn climate(&self, x: i32, z: i32) -> (Vec<f64>, Vec<f64>, Vec<&'static Biome>) {
        let (fx, fz) = (x as f64, z as f64);
        let mut temperatures = self.temperature.grid(fx, fz, 16, 16, 0.025, 0.025, 0.25);
        let mut rainfalls = self.rainfall.grid(fx, fz, 16, 16, 0.05, 0.05, 1.0 / 3.0);
        let detail = self.climate_detail.grid(fx, fz, 16, 16, 0.25, 0.25, 0.588);
        let warp_x = self.warp_x.grid_2d(fx, fz, 16, 16, 0.03, 0.03);
        let warp_z = self.warp_z.grid_2d(fx, fz, 16, 16, 0.03, 0.03);
        let mut biomes = Vec::with_capacity(256);

        for i in 0..256 {
            let fine = detail[i] * 1.1 + 0.5;
            let temperature = (temperatures[i] * 0.15 + 0.7) * 0.99 + fine * 0.01;
            let rainfall = ((rainfalls[i] * 0.15 + 0.5) * 0.998 + fine * 0.002).clamp(0.0, 1.0);
            let temperature = (1.0 - (1.0 - temperature) * (1.0 - temperature)).clamp(0.0, 1.0);

            temperatures[i] = temperature;
            rainfalls[i] = rainfall;

            let kinds = biome::climate(temperature, rainfall);
            let (px, pz) = (fx + (i / 16) as f64 + warp_x[i] * 12.0, fz + (i % 16) as f64 + warp_z[i] * 12.0);
            biomes.push(kinds[self.variant(px, pz) % kinds.len()]);
        }

        (temperatures, rainfalls, biomes)
    }

    /// Which of a climate's modern biomes a spot gets: the world is split into cells around
    /// scattered points, each cell takes the biome its point rolls.
    fn variant(&self, x: f64, z: f64) -> usize {
        let (cell_x, cell_z) = ((x / VARIANT_CELL).floor() as i64, (z / VARIANT_CELL).floor() as i64);
        let mut nearest = (f64::MAX, 0u64);

        for nx in cell_x - 1..=cell_x + 1 {
            for nz in cell_z - 1..=cell_z + 1 {
                let hash = hash(self.seed, nx, nz);
                let point_x = (nx as f64 + (hash & 0xffff) as f64 / 65536.0) * VARIANT_CELL;
                let point_z = (nz as f64 + ((hash >> 16) & 0xffff) as f64 / 65536.0) * VARIANT_CELL;
                let distance = (point_x - x) * (point_x - x) + (point_z - z) * (point_z - z);

                if distance < nearest.0 {
                    nearest = (distance, hash);
                }
            }
        }

        (nearest.1 >> 32) as usize
    }

    /// The 5 x 17 x 5 density grid of a chunk: positive is solid.
    fn density(&self, chunk_x: i32, chunk_z: i32, temperatures: &[f64], rainfalls: &[f64]) -> Vec<f64> {
        const SX: usize = 5;
        const SY: usize = 17;
        const SZ: usize = 5;
        let (x, z) = ((chunk_x * 4) as f64, (chunk_z * 4) as f64);
        let horizontal = 684.412;
        let vertical = 684.412;

        let scale = self.scale.grid_2d(x, z, SX, SZ, 1.121, 1.121);
        let depth = self.depth.grid_2d(x, z, SX, SZ, 200.0, 200.0);
        let main = self.main.grid(x, 0.0, z, SX, SY, SZ, horizontal / 80.0, vertical / 160.0, horizontal / 80.0);
        let min_limit = self.min_limit.grid(x, 0.0, z, SX, SY, SZ, horizontal, vertical, horizontal);
        let max_limit = self.max_limit.grid(x, 0.0, z, SX, SY, SZ, horizontal, vertical, horizontal);

        let mut out = vec![0.0; SX * SY * SZ];
        let mut column = 0;
        let mut index = 0;

        for i in 0..SX {
            let sample_x = i * 3 + 1;

            for k in 0..SZ {
                let sample_z = k * 3 + 1;
                let temperature = temperatures[sample_x * 16 + sample_z];
                let wetness = 1.0 - (1.0 - rainfalls[sample_x * 16 + sample_z] * temperature).powi(4);

                let mut height_scale = ((scale[column] + 256.0) / 512.0 * wetness).min(1.0);
                let mut height_depth = depth[column] / 8000.0;

                if height_depth < 0.0 {
                    height_depth = -height_depth * 0.3;
                }

                height_depth = height_depth * 3.0 - 2.0;

                if height_depth < 0.0 {
                    height_depth = (height_depth / 2.0).max(-1.0) / 1.4 / 2.0;
                    height_scale = 0.0;
                } else {
                    height_depth = height_depth.min(1.0) / 8.0;
                }

                let height_scale = height_scale.max(0.0) + 0.5;
                let center = SY as f64 / 2.0 + height_depth * SY as f64 / 16.0 * 4.0;
                column += 1;

                for j in 0..SY {
                    let mut falloff = (j as f64 - center) * 12.0 / height_scale;

                    if falloff < 0.0 {
                        falloff *= 4.0;
                    }

                    let low = min_limit[index] / 512.0;
                    let high = max_limit[index] / 512.0;
                    let blend = (main[index] / 10.0 + 1.0) / 2.0;
                    let mut value = if blend < 0.0 { low } else if blend > 1.0 { high } else { low + (high - low) * blend } - falloff;

                    //the top of the world closes off
                    if j > SY - 4 {
                        let top = (j - (SY - 4)) as f64 / 3.0;
                        value = value * (1.0 - top) - 10.0 * top;
                    }

                    out[index] = value;
                    index += 1;
                }
            }
        }

        out
    }
}

fn hash(seed: i64, x: i64, z: i64) -> u64 {
    let mut value = (seed as u64) ^ (x as u64).wrapping_mul(0x9e37_79b9_7f4a_7c15) ^ (z as u64).wrapping_mul(0xc2b2_ae3d_27d4_eb4f);
    value ^= value >> 33;
    value = value.wrapping_mul(0xff51_afd7_ed55_8ccd);
    value ^= value >> 33;
    value = value.wrapping_mul(0xc4ce_b9fe_1a85_ec53);
    value ^ (value >> 33)
}

/// The chunk's random, seeded from its position the classic way.
fn chunk_random(chunk_x: i32, chunk_z: i32) -> JavaRandom {
    JavaRandom::new((chunk_x as i64).wrapping_mul(341_873_128_712).wrapping_add((chunk_z as i64).wrapping_mul(132_897_987_541)))
}

/// Stone, water and ice from the density grid, the grid stretched over the chunk.
fn terrain(column: &mut [Block], density: &[f64], temperatures: &[f64]) {
    const SY: usize = 17;
    const SZ: usize = 5;

    for cell_x in 0..4 {
        for cell_z in 0..4 {
            for cell_y in 0..16 {
                let at = |x: usize, z: usize, y: usize| density[((cell_x + x) * SZ + cell_z + z) * SY + cell_y + y];
                let (mut c00, mut c01, mut c10, mut c11) = (at(0, 0, 0), at(0, 1, 0), at(1, 0, 0), at(1, 1, 0));
                let step00 = (at(0, 0, 1) - c00) * 0.125;
                let step01 = (at(0, 1, 1) - c01) * 0.125;
                let step10 = (at(1, 0, 1) - c10) * 0.125;
                let step11 = (at(1, 1, 1) - c11) * 0.125;

                for sub_y in 0..8 {
                    let y = (cell_y * 8 + sub_y) as i32;
                    let (mut near, mut far) = (c00, c01);
                    let near_step = (c10 - c00) * 0.25;
                    let far_step = (c11 - c01) * 0.25;

                    for sub_x in 0..4 {
                        let x = cell_x * 4 + sub_x;
                        let mut value = near;
                        let step = (far - near) * 0.25;

                        for sub_z in 0..4 {
                            let z = cell_z * 4 + sub_z;
                            let mut block = blocks::AIR;

                            if y < SEA_LEVEL {
                                block = if temperatures[x * 16 + z] < 0.5 && y >= SEA_LEVEL - 1 { blocks::ICE } else { blocks::WATER };
                            }

                            if value > 0.0 {
                                block = blocks::STONE;
                            }

                            column[(x * 16 + z) * HEIGHT as usize + y as usize] = block;
                            value += step;
                        }

                        near += near_step;
                        far += far_step;
                    }

                    c00 += step00;
                    c01 += step01;
                    c10 += step10;
                    c11 += step11;
                }
            }
        }
    }
}

/// Grass, sand and the rest over the stone, bedrock at the bottom.
fn surface(column: &mut [Block], noises: &Noises, random: &mut JavaRandom, chunk_x: i32, chunk_z: i32, biomes: &[&'static Biome]) {
    let scale = 0.03125;
    let (x0, z0) = ((chunk_x * 16) as f64, (chunk_z * 16) as f64);
    let sand = noises.beach.grid(x0, z0, 0.0, 16, 16, 1, scale, scale, 1.0);
    let gravel = noises.beach.grid_2d(x0, z0, 16, 16, scale, scale);
    let depths = noises.surface.grid(x0, z0, 0.0, 16, 16, 1, scale * 2.0, scale * 2.0, scale * 2.0);
    let ground_detail = noises.ground.grid_2d(x0, z0, 16, 16, 0.08, 0.08);

    for x in 0..16usize {
        for z in 0..16usize {
            let biome = biomes[x * 16 + z];
            let beach_sand = sand[x * 16 + z] + random.next_double() * 0.2 > 0.0;
            let beach_gravel = gravel[x * 16 + z] + random.next_double() * 0.2 > 3.0;
            let depth = (depths[x * 16 + z] / 3.0 + 3.0 + random.next_double() * 0.25) as i32;
            let band_offset = (ground_detail[x * 16 + z] * 3.0) as i32;
            let mut remaining = -1;
            let (mut top, mut filler) = (biome.top, biome.filler);

            if biome.ground == Ground::Podzol && ground_detail[x * 16 + z] > 0.1 {
                top = if ground_detail[x * 16 + z] > 0.5 { blocks::COARSE_DIRT } else { blocks::PODZOL };
            }

            let column = &mut column[(x * 16 + z) * HEIGHT as usize..(x * 16 + z + 1) * HEIGHT as usize];

            for y in (0..HEIGHT).rev() {
                if y <= random.next_int_bounded(5) {
                    column[y as usize] = blocks::BEDROCK;
                    continue;
                }

                let block = column[y as usize];

                if block == blocks::AIR {
                    remaining = -1;
                    continue;
                }

                if block != blocks::STONE {
                    continue;
                }

                if remaining == -1 {
                    if depth <= 0 {
                        top = blocks::AIR;
                        filler = blocks::STONE;
                    } else if y >= SEA_LEVEL - 4 && y <= SEA_LEVEL + 1 {
                        top = biome.top;
                        filler = biome.filler;

                        if beach_gravel {
                            top = blocks::AIR;
                            filler = blocks::GRAVEL;
                        }

                        if beach_sand && biome.ground != Ground::Badlands {
                            top = blocks::SAND;
                            filler = blocks::SAND;
                        }
                    }

                    if y < SEA_LEVEL && top == blocks::AIR {
                        top = blocks::WATER;
                    }

                    remaining = if biome.ground == Ground::Badlands { depth * 4 } else { depth };
                    column[y as usize] = if y >= SEA_LEVEL - 1 { top } else { filler };

                    //what grows on top does not grow under water
                    if y < SEA_LEVEL - 1 && blocks::is_soil(column[y as usize]) {
                        column[y as usize] = blocks::DIRT;
                    }
                } else if remaining > 0 {
                    remaining -= 1;
                    column[y as usize] = if filler == blocks::TERRACOTTA { TERRACOTTA_BANDS[((y + band_offset).rem_euclid(TERRACOTTA_BANDS.len() as i32)) as usize] } else { filler };

                    if remaining == 0 && filler == blocks::SAND {
                        remaining = random.next_int_bounded(4);
                        filler = blocks::SANDSTONE;
                    } else if remaining == 0 && filler == blocks::RED_SAND {
                        remaining = random.next_int_bounded(4);
                        filler = blocks::RED_SANDSTONE;
                    }
                }
            }
        }
    }
}

/// The classic caves: long winding tunnels and the odd round room, lava near the bottom.
struct Caves<'a> {
    random: JavaRandom,
    column: &'a mut [Block],
    chunk_x: i32,
    chunk_z: i32,
}

impl Caves<'_> {
    fn carve_chunk(column: &mut [Block], seed: i64, chunk_x: i32, chunk_z: i32) {
        let mut random = JavaRandom::new(seed);
        let a = random.next_long() / 2 * 2 + 1;
        let b = random.next_long() / 2 * 2 + 1;
        let mut caves = Caves { random, column, chunk_x, chunk_z };

        for from_x in chunk_x - CAVE_RANGE..=chunk_x + CAVE_RANGE {
            for from_z in chunk_z - CAVE_RANGE..=chunk_z + CAVE_RANGE {
                caves.random.set_seed((from_x as i64).wrapping_mul(a).wrapping_add((from_z as i64).wrapping_mul(b)) ^ seed);
                caves.start(from_x, from_z);
            }
        }
    }

    fn start(&mut self, from_x: i32, from_z: i32) {
        let random = &mut self.random;
        let inner = random.next_int_bounded(40) + 1;
        let middle = random.next_int_bounded(inner) + 1;
        let mut count = random.next_int_bounded(middle);

        if random.next_int_bounded(15) != 0 {
            count = 0;
        }

        for _ in 0..count {
            let x = (from_x * 16 + self.random.next_int_bounded(16)) as f64;
            let bound = self.random.next_int_bounded(120) + 8;
            let y = self.random.next_int_bounded(bound) as f64;
            let z = (from_z * 16 + self.random.next_int_bounded(16)) as f64;
            let mut tunnels = 1;

            if self.random.next_int_bounded(4) == 0 {
                let size = 1.0 + self.random.next_float() as f64 * 6.0;
                let seed = self.random.next_long();
                self.tunnel(seed, x, y, z, size, 0.0, 0.0, -1, -1, 0.5);
                tunnels += self.random.next_int_bounded(4);
            }

            for _ in 0..tunnels {
                let yaw = self.random.next_float() as f64 * PI * 2.0;
                let pitch = (self.random.next_float() as f64 - 0.5) * 2.0 / 8.0;
                let width = self.random.next_float() as f64 * 2.0 + self.random.next_float() as f64;
                let seed = self.random.next_long();
                self.tunnel(seed, x, y, z, width, yaw, pitch, 0, 0, 1.0);
            }
        }
    }

    #[allow(clippy::too_many_arguments)]
    fn tunnel(&mut self, seed: i64, mut x: f64, mut y: f64, mut z: f64, width: f64, mut yaw: f64, mut pitch: f64, mut step: i32, mut length: i32, vertical_scale: f64) {
        let center_x = (self.chunk_x * 16 + 8) as f64;
        let center_z = (self.chunk_z * 16 + 8) as f64;
        let (mut yaw_change, mut pitch_change) = (0.0f64, 0.0f64);
        let mut random = JavaRandom::new(seed);

        if length <= 0 {
            let most = CAVE_RANGE * 16 - 16;
            length = most - random.next_int_bounded(most / 4);
        }

        let room = step == -1;

        if room {
            step = length / 2;
        }

        let branch_at = random.next_int_bounded(length / 2) + length / 4;
        let steep = random.next_int_bounded(6) == 0;

        while step < length {
            let radius = 1.5 + (step as f64 * PI / length as f64).sin() * width;
            let radius_y = radius * vertical_scale;
            let (cos_pitch, sin_pitch) = (pitch.cos(), pitch.sin());
            x += yaw.cos() * cos_pitch;
            y += sin_pitch;
            z += yaw.sin() * cos_pitch;
            pitch *= if steep { 0.92 } else { 0.7 };
            pitch += pitch_change * 0.1;
            yaw += yaw_change * 0.1;
            pitch_change *= 0.9;
            yaw_change *= 0.75;
            pitch_change += (random.next_float() - random.next_float()) as f64 * random.next_float() as f64 * 2.0;
            yaw_change += (random.next_float() - random.next_float()) as f64 * random.next_float() as f64 * 4.0;

            if !room && step == branch_at && width > 1.0 {
                let (left, right) = (random.next_long(), random.next_long());
                let (left_width, right_width) = (random.next_float() as f64 * 0.5 + 0.5, random.next_float() as f64 * 0.5 + 0.5);
                self.tunnel(left, x, y, z, left_width, yaw - PI / 2.0, pitch / 3.0, step, length, 1.0);
                self.tunnel(right, x, y, z, right_width, yaw + PI / 2.0, pitch / 3.0, step, length, 1.0);
                return;
            }

            step += 1;

            if !room && random.next_int_bounded(4) == 0 {
                continue;
            }

            let (dx, dz, left) = (x - center_x, z - center_z, (length - step) as f64);
            let reach = width + 2.0 + 16.0;

            if dx * dx + dz * dz - left * left > reach * reach {
                return;
            }

            if x < center_x - 16.0 - radius * 2.0 || z < center_z - 16.0 - radius * 2.0 || x > center_x + 16.0 + radius * 2.0 || z > center_z + 16.0 + radius * 2.0 {
                continue;
            }

            self.carve(x, y, z, radius, radius_y);

            if room {
                break;
            }
        }
    }

    fn carve(&mut self, x: f64, y: f64, z: f64, radius: f64, radius_y: f64) {
        let base_x = self.chunk_x * 16;
        let base_z = self.chunk_z * 16;
        let min_x = ((x - radius).floor() as i32 - base_x - 1).max(0);
        let max_x = ((x + radius).floor() as i32 - base_x + 1).min(16);
        let min_y = ((y - radius_y).floor() as i32 - 1).max(1);
        let max_y = ((y + radius_y).floor() as i32 + 1).min(HEIGHT - 8);
        let min_z = ((z - radius).floor() as i32 - base_z - 1).max(0);
        let max_z = ((z + radius).floor() as i32 - base_z + 1).min(16);
        let at = |bx: i32, by: i32, bz: i32| ((bx * 16 + bz) * HEIGHT + by) as usize;

        //caves stop at water
        for bx in min_x..max_x {
            for bz in min_z..max_z {
                for by in (min_y - 1..=max_y + 1).rev() {
                    if (0..HEIGHT).contains(&by) && matches!(self.column[at(bx, by, bz)], blocks::WATER | blocks::ICE) {
                        return;
                    }
                }
            }
        }

        for bx in min_x..max_x {
            let fx = ((bx + base_x) as f64 + 0.5 - x) / radius;

            for bz in min_z..max_z {
                let fz = ((bz + base_z) as f64 + 0.5 - z) / radius;

                if fx * fx + fz * fz >= 1.0 {
                    continue;
                }

                let mut grass = false;

                for by in (min_y..max_y).rev() {
                    let fy = (by as f64 + 0.5 - y) / radius_y;

                    if fy <= -0.7 || fx * fx + fy * fy + fz * fz >= 1.0 {
                        continue;
                    }

                    let block = self.column[at(bx, by, bz)];

                    if blocks::is_soil(block) && block != blocks::DIRT {
                        grass = true;
                    }

                    if matches!(block, blocks::STONE | blocks::DIRT | blocks::GRASS_BLOCK | blocks::PODZOL | blocks::COARSE_DIRT | blocks::MYCELIUM | blocks::SANDSTONE | blocks::RED_SANDSTONE | blocks::TERRACOTTA)
                        || (blocks::TERRACOTTA..=blocks::LIGHT_GRAY_TERRACOTTA).contains(&block)
                    {
                        if by < 10 {
                            self.column[at(bx, by, bz)] = blocks::LAVA;
                        } else {
                            self.column[at(bx, by, bz)] = blocks::AIR;

                            //the grass over a cave comes down with it onto the dirt below
                            if grass && by > 0 && self.column[at(bx, by - 1, bz)] == blocks::DIRT {
                                self.column[at(bx, by - 1, bz)] = blocks::GRASS_BLOCK;
                            }
                        }
                    }
                }
            }
        }
    }
}

/// Makes one chunk's terrain: its blocks x, then z, then y, and its biomes.
fn make_chunk(noises: &Noises, seed: i64, chunk_x: i32, chunk_z: i32) -> (Vec<Block>, Vec<&'static Biome>) {
    let mut random = chunk_random(chunk_x, chunk_z);
    let (temperatures, rainfalls, mut biomes) = noises.climate(chunk_x * 16, chunk_z * 16);
    let density = noises.density(chunk_x, chunk_z, &temperatures, &rainfalls);
    let mut column = vec![blocks::AIR; 16 * 16 * HEIGHT as usize];

    terrain(&mut column, &density, &temperatures);

    //the sea gets ocean biomes, the shore beaches
    for x in 0..16usize {
        for z in 0..16usize {
            let base = (x * 16 + z) * HEIGHT as usize;
            let stone = (0..HEIGHT as usize).rev().find(|y| column[base + y] == blocks::STONE).unwrap_or(0) as i32;
            let temperature = temperatures[x * 16 + z];

            if stone < SEA_LEVEL - 4 {
                biomes[x * 16 + z] = if temperature < 0.4 { &biome::FROZEN_OCEAN } else if temperature > 0.95 { &biome::WARM_OCEAN } else { &biome::OCEAN };
            }
        }
    }

    surface(&mut column, noises, &mut random, chunk_x, chunk_z, &biomes);

    for x in 0..16usize {
        for z in 0..16usize {
            let base = (x * 16 + z) * HEIGHT as usize;
            let top = (0..HEIGHT as usize).rev().find(|y| column[base + y] != blocks::AIR).unwrap_or(0) as i32;
            let current = biomes[x * 16 + z];

            if column[base + top as usize] == blocks::SAND && (SEA_LEVEL - 2..=SEA_LEVEL + 2).contains(&top) && current.ground != Ground::Desert {
                biomes[x * 16 + z] = if current.snowy { &biome::SNOWY_BEACH } else { &biome::BEACH };
            }
        }
    }

    Caves::carve_chunk(&mut column, seed, chunk_x, chunk_z);

    (column, biomes)
}

fn pick_tree(random: &mut JavaRandom, kinds: &[(Tree, u32)]) -> Tree {
    let total: u32 = kinds.iter().map(|(_, weight)| weight).sum();
    let mut roll = random.next_int_bounded(total.max(1) as i32) as u32;

    for (kind, weight) in kinds {
        if roll < *weight {
            return *kind;
        }

        roll -= weight;
    }

    kinds[0].0
}

/// Ores, trees, plants and snow for one chunk, the classic way: spread over the 16 x 16 area
/// half a chunk further on, so they reach into the neighbours.
fn populate(world: &mut World, noises: &Noises, seed: i64, chunk_x: i32, chunk_z: i32) {
    let mut random = JavaRandom::new(seed);
    let a = random.next_long() / 2 * 2 + 1;
    let b = random.next_long() / 2 * 2 + 1;
    random.set_seed((chunk_x as i64).wrapping_mul(a).wrapping_add((chunk_z as i64).wrapping_mul(b)) ^ seed);

    let (x0, z0) = (chunk_x * 16, chunk_z * 16);
    let biome = world.biome(x0 + 16, z0 + 16);

    let ores: [(Block, i32, i32, i32); 9] = [
        (blocks::DIRT, 20, 32, HEIGHT),
        (blocks::GRAVEL, 10, 32, HEIGHT),
        (blocks::COAL_ORE, 20, 16, HEIGHT),
        (blocks::IRON_ORE, 20, 8, HEIGHT / 2),
        (blocks::COPPER_ORE, 8, 10, 96),
        (blocks::GOLD_ORE, 2, 8, HEIGHT / 4),
        (blocks::REDSTONE_ORE, 8, 7, HEIGHT / 8),
        (blocks::DIAMOND_ORE, 1, 7, HEIGHT / 8),
        (blocks::LAPIS_ORE, 1, 6, 0),
    ];

    for (ore, count, size, max_y) in ores {
        for _ in 0..count {
            let x = x0 + random.next_int_bounded(16);
            //lapis is most common in the middle of its range
            let y = if max_y == 0 { random.next_int_bounded(16) + random.next_int_bounded(16) } else { random.next_int_bounded(max_y) };
            let z = z0 + random.next_int_bounded(16);
            features::ore(world, &mut random, x, y, z, ore, size);
        }
    }

    let (cx, cz) = (x0 + 8, z0 + 8);

    //trees: a few more or less depending on the tree noise
    let noise = noises.trees.grid_2d(cx as f64 * 0.5, cz as f64 * 0.5, 1, 1, 1.0, 1.0)[0];
    let extra = ((noise / 8.0 + random.next_double() * 4.0 + 4.0) / 3.0) as i32;
    let mut trees = if biome.trees > 0 { biome.trees as i32 + extra - 3 } else { 0 };

    if biome.stray_tree > 0 && random.next_int_bounded(biome.stray_tree as i32) == 0 {
        trees += 1;
    }

    for _ in 0..trees.max(0) {
        let x = cx + random.next_int_bounded(16);
        let z = cz + random.next_int_bounded(16);
        let here = world.biome(x, z);
        let kinds = if here.trees > 0 || here.stray_tree > 0 { here.tree_kinds } else { biome.tree_kinds };
        let kind = pick_tree(&mut random, kinds);
        let y = world.top(x, z);
        features::tree(world, &mut random, kind, x, y, z);
    }

    if biome.grass > 0 {
        let ferns = biome.ferns;
        features::scatter(world, &mut random, cx, cz, biome.grass, 8, |random| if ferns && random.next_int_bounded(4) == 0 { blocks::FERN } else { blocks::SHORT_GRASS }, &[]);
    }

    if biome.tall_grass > 0 {
        let (bottom, top) = if biome.ferns { (blocks::LARGE_FERN_BOTTOM, blocks::LARGE_FERN_TOP) } else { (blocks::TALL_GRASS_BOTTOM, blocks::TALL_GRASS_TOP) };
        for _ in 0..biome.tall_grass {
            features::scatter_tall(world, &mut random, cx, cz, 4, bottom, top);
        }
    }

    if biome.flowers > 0 {
        let kinds = biome.flower_kinds;
        features::scatter(world, &mut random, cx, cz, biome.flowers, 6, |random| kinds[random.next_int_bounded(kinds.len() as i32) as usize], &[]);
    }

    if biome.id == biome::SUNFLOWER_PLAINS.id {
        features::scatter_tall(world, &mut random, cx, cz, 24, blocks::SUNFLOWER_BOTTOM, blocks::SUNFLOWER_TOP);
    }

    if biome.cactus > 0 {
        features::cacti(world, &mut random, cx, cz, biome.cactus);
    }

    if biome.dead_bushes > 0 {
        let ground = [blocks::SAND, blocks::RED_SAND, blocks::TERRACOTTA, blocks::ORANGE_TERRACOTTA];
        features::scatter(world, &mut random, cx, cz, biome.dead_bushes, 1, |_| blocks::DEAD_BUSH, &ground);
    }

    if biome.id == biome::BAMBOO_JUNGLE.id {
        features::bamboo(world, &mut random, cx, cz, 24);
    }

    if biome.ground == Ground::Mycelium {
        features::scatter(world, &mut random, cx, cz, 2, 4, |random| if random.next_bool() { blocks::RED_MUSHROOM } else { blocks::BROWN_MUSHROOM }, &[]);
    }

    if biome.id == biome::ICE_SPIKES.id && random.next_int_bounded(3) == 0 {
        let (x, z) = (cx + random.next_int_bounded(16), cz + random.next_int_bounded(16));
        features::ice_spike(world, &mut random, x, z);
    }

    features::sugar_cane(world, &mut random, cx, cz, 20);

    if random.next_int_bounded(32) == 0 {
        features::scatter(world, &mut random, cx, cz, 1, 8, |_| blocks::PUMPKIN, &[]);
    }

    if (biome.id == biome::JUNGLE.id || biome.id == biome::BAMBOO_JUNGLE.id) && random.next_int_bounded(4) == 0 {
        features::scatter(world, &mut random, cx, cz, 1, 8, |_| blocks::MELON, &[]);
    }
}

/// Snow on cold ground and ice on cold water, over the whole world at the end.
fn freeze(world: &mut World) {
    for x in 0..SIZE {
        for z in 0..SIZE {
            if !world.biome(x, z).snowy {
                continue;
            }

            let y = world.top(x, z);
            let below = world.get(x, y - 1, z);

            if below == blocks::WATER {
                world.set(x, y - 1, z, blocks::ICE);
            } else if y < HEIGHT && (blocks::is_solid(below) || blocks::is_leaves(below)) && below != blocks::PACKED_ICE {
                world.set(x, y, z, blocks::SNOW_LAYER);
            }
        }
    }
}

/// A spot near the middle to spawn: dry ground under open sky.
fn find_spawn(world: &World) -> (i32, i32, i32) {
    let middle = SIZE / 2;

    for radius in 0..middle {
        for dx in -radius..=radius {
            for dz in -radius..=radius {
                if dx.abs() != radius && dz.abs() != radius {
                    continue;
                }

                let (x, z) = (middle + dx, middle + dz);
                let y = world.ground(x, z);
                let below = world.get(x, y - 1, z);

                if y > SEA_LEVEL && (blocks::is_soil(below) || matches!(below, blocks::SAND | blocks::RED_SAND | blocks::SNOW)) && blocks::is_replaceable(world.get(x, y, z)) && world.get(x, y + 1, z) == blocks::AIR {
                    return (x, y, z);
                }
            }
        }
    }

    (middle, world.top(middle, middle), middle)
}

/// One chunk of the finished world as storage, with `states` turning blocks into state IDs.
fn to_chunk(world: &World, chunk_x: i32, chunk_z: i32, states: &[u32], air: u32) -> Chunk {
    let mut chunk = Chunk::new(air, biome::PLAINS.id as u32);
    let (x0, z0) = (chunk_x * 16, chunk_z * 16);

    let mut biome_palette: Vec<u32> = Vec::new();
    let mut biome_offsets = vec![0u16; 4096];

    for x in 0..16 {
        for z in 0..16 {
            let id = world.biome(x0 + x, z0 + z).id as u32;
            let offset = biome_palette.iter().position(|entry| *entry == id).unwrap_or_else(|| {
                biome_palette.push(id);
                biome_palette.len() - 1
            });

            for y in 0..16 {
                biome_offsets[crate::chunk::index(x as usize, y, z as usize)] = offset as u16;
            }
        }
    }

    let biomes = if biome_palette.len() == 1 { Storage { palette: biome_palette, indices: None } } else { Storage { palette: biome_palette, indices: Some(biome_offsets.into_boxed_slice()) } };

    for sub in 0..chunk.subchunks.len() {
        let bottom = (sub as i32 + MIN_SUBCHUNK) * 16;
        let mut layer = Storage::new(air);

        if (0..HEIGHT).contains(&bottom) {
            for x in 0..16 {
                for z in 0..16 {
                    for y in 0..16 {
                        let block = world.blocks[index(x0 + x, bottom + y, z0 + z)];

                        if block != blocks::AIR {
                            layer.set(crate::chunk::index(x as usize, y as usize, z as usize), states[block as usize]);
                        }
                    }
                }
            }
        }

        chunk.subchunks[sub] = Arc::new(SubChunk { layers: vec![layer], biomes: biomes.clone() });
    }

    chunk
}

pub struct Generated {
    /// x * SIZE_CHUNKS + z
    pub chunks: Vec<Chunk>,
    pub spawn: (i32, i32, i32),
}

/// Makes the whole Old world. `states` holds the state ID of each block in blocks::STATES.
pub fn generate(seed: i64, states: &[u32]) -> Generated {
    let noises = Noises::new(seed);
    let threads = thread::available_parallelism().map_or(2, |cores| cores.get()).clamp(1, 16);
    let mut made: Vec<Option<(Vec<Block>, Vec<&'static Biome>)>> = (0..SIZE_CHUNKS * SIZE_CHUNKS).map(|_| None).collect();

    thread::scope(|scope| {
        let noises = &noises;

        for (part, slots) in made.chunks_mut((SIZE_CHUNKS * SIZE_CHUNKS) as usize / threads + 1).enumerate() {
            let first = part * ((SIZE_CHUNKS * SIZE_CHUNKS) as usize / threads + 1);

            scope.spawn(move || {
                for (offset, slot) in slots.iter_mut().enumerate() {
                    let at = (first + offset) as i32;
                    *slot = Some(make_chunk(noises, seed, at / SIZE_CHUNKS, at % SIZE_CHUNKS));
                }
            });
        }
    });

    let mut world = World { blocks: vec![blocks::AIR; (SIZE * SIZE * HEIGHT) as usize], biomes: vec![&biome::PLAINS; (SIZE * SIZE) as usize] };

    for (at, slot) in made.into_iter().enumerate() {
        let (column, biomes) = slot.unwrap();
        let (chunk_x, chunk_z) = (at as i32 / SIZE_CHUNKS, at as i32 % SIZE_CHUNKS);

        for x in 0..16 {
            for z in 0..16 {
                let (wx, wz) = (chunk_x * 16 + x, chunk_z * 16 + z);
                let from = ((x * 16 + z) * HEIGHT) as usize;
                let to = index(wx, 0, wz);
                world.blocks[to..to + HEIGHT as usize].copy_from_slice(&column[from..from + HEIGHT as usize]);
                world.biomes[(wx * SIZE + wz) as usize] = biomes[(x * 16 + z) as usize];
            }
        }
    }

    for chunk_x in 0..SIZE_CHUNKS {
        for chunk_z in 0..SIZE_CHUNKS {
            populate(&mut world, &noises, seed, chunk_x, chunk_z);
        }
    }

    freeze(&mut world);

    let spawn = find_spawn(&world);
    let air = states[blocks::AIR as usize];
    let mut chunks = Vec::with_capacity((SIZE_CHUNKS * SIZE_CHUNKS) as usize);

    for chunk_x in 0..SIZE_CHUNKS {
        for chunk_z in 0..SIZE_CHUNKS {
            chunks.push(to_chunk(&world, chunk_x, chunk_z, states, air));
        }
    }

    Generated { chunks, spawn }
}

/// A chunk outside the world: empty, with the barrier wall where it borders the world.
pub fn border(chunk: &mut Chunk, chunk_x: i32, chunk_z: i32, barrier: u32) {
    for x in 0..16 {
        for z in 0..16 {
            let (wx, wz) = (chunk_x * 16 + x, chunk_z * 16 + z);
            let on_x = (wx == -1 || wx == SIZE) && (-1..=SIZE).contains(&wz);
            let on_z = (wz == -1 || wz == SIZE) && (-1..=SIZE).contains(&wx);

            if on_x || on_z {
                for y in crate::chunk::MIN_Y..=crate::chunk::MAX_Y {
                    chunk.set_block(x as usize, y, z as usize, 0, barrier);
                }
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn makes_a_world() {
        let states: Vec<u32> = (0..blocks::count() as u32).map(|id| id + 1000).collect();
        let started = std::time::Instant::now();
        let generated = generate(12345, &states);
        eprintln!("old world in {:?}, spawn {:?}", started.elapsed(), generated.spawn);

        assert_eq!(generated.chunks.len(), 256);
        let (x, y, z) = generated.spawn;
        assert!((0..SIZE).contains(&x) && (0..SIZE).contains(&z) && y > SEA_LEVEL);

        //bedrock at the bottom everywhere, nothing at or above 128
        let chunk = &generated.chunks[17];
        assert_eq!(chunk.get_block(3, 0, 3, 0), states[blocks::BEDROCK as usize]);
        assert_eq!(chunk.get_block(3, 128, 3, 0), states[blocks::AIR as usize]);

        //the same seed makes the same world
        let again = generate(12345, &states);
        assert_eq!(again.spawn, generated.spawn);
        assert_eq!(again.chunks[100].get_block(5, 70, 9, 0), generated.chunks[100].get_block(5, 70, 9, 0));
    }

    #[test]
    fn border_walls() {
        let mut chunk = Chunk::new(1, 1);
        border(&mut chunk, -1, 3, 9);
        assert_eq!(chunk.get_block(15, 64, 0, 0), 9);
        assert_eq!(chunk.get_block(14, 64, 0, 0), 1);
        let mut far = Chunk::new(1, 1);
        border(&mut far, -5, 3, 9);
        assert_eq!(far.highest_subchunk(), None);
    }
}

#[cfg(test)]
mod map {
    use super::*;

    /// RX_MAP=path cargo test --release dump_map -- --ignored: a top-down picture of a world.
    #[test]
    #[ignore]
    fn dump_map() {
        let seed: i64 = std::env::var("RX_SEED").ok().and_then(|seed| seed.parse().ok()).unwrap_or(12345);
        let states: Vec<u32> = (0..blocks::count() as u32).collect();
        let generated = generate(seed, &states);
        let mut out = format!("P3\n{SIZE} {SIZE}\n255\n").into_bytes();
        let mut side = format!("P3\n{SIZE} {HEIGHT}\n255\n").into_bytes();

        let color = |block: u32| -> (u8, u8, u8) {
            match block as Block {
                blocks::GRASS_BLOCK => (90, 160, 60),
                blocks::DIRT | blocks::COARSE_DIRT => (130, 90, 60),
                blocks::PODZOL => (110, 75, 40),
                blocks::MYCELIUM => (130, 110, 140),
                blocks::SAND => (220, 210, 150),
                blocks::RED_SAND => (190, 100, 40),
                blocks::GRAVEL => (130, 130, 130),
                blocks::STONE => (110, 110, 110),
                blocks::WATER => (50, 80, 200),
                blocks::ICE | blocks::PACKED_ICE => (160, 200, 250),
                blocks::SNOW_LAYER | blocks::SNOW => (250, 250, 250),
                blocks::LAVA => (250, 90, 0),
                block if blocks::is_leaves(block) => (30, 110, 30),
                block if blocks::is_log(block) => (100, 70, 40),
                block if (blocks::TERRACOTTA..=blocks::LIGHT_GRAY_TERRACOTTA).contains(&block) => (170, 90, 60),
                block if blocks::is_plant(block) => (120, 190, 80),
                blocks::BEDROCK => (20, 20, 20),
                blocks::AIR => (200, 230, 255),
                _ => (255, 0, 255),
            }
        };

        for z in 0..SIZE {
            for x in 0..SIZE {
                let chunk = &generated.chunks[((x >> 4) * SIZE_CHUNKS + (z >> 4)) as usize];
                let mut y = HEIGHT - 1;

                while y > 0 && chunk.get_block((x & 15) as usize, y, (z & 15) as usize, 0) == blocks::AIR as u32 {
                    y -= 1;
                }

                let (r, g, b) = color(chunk.get_block((x & 15) as usize, y, (z & 15) as usize, 0));
                let shade = 0.6 + 0.4 * (y as f64 / HEIGHT as f64);
                out.extend_from_slice(format!("{} {} {} ", (r as f64 * shade) as u8, (g as f64 * shade) as u8, (b as f64 * shade) as u8).as_bytes());
            }
        }

        let z = SIZE / 2;

        for y in (0..HEIGHT).rev() {
            for x in 0..SIZE {
                let chunk = &generated.chunks[((x >> 4) * SIZE_CHUNKS + (z >> 4)) as usize];
                let (r, g, b) = color(chunk.get_block((x & 15) as usize, y, (z & 15) as usize, 0));
                side.extend_from_slice(format!("{r} {g} {b} ").as_bytes());
            }
        }

        let path = std::env::var("RX_MAP").unwrap_or("/tmp/old".into());
        std::fs::write(format!("{path}_top.ppm"), out).unwrap();
        std::fs::write(format!("{path}_side.ppm"), side).unwrap();

        let mut counts = std::collections::BTreeMap::new();

        for chunk in &generated.chunks {
            for x in 0..16 {
                for z in 0..16 {
                    let id = chunk.get_biome(x, 64, z);
                    *counts.entry(id).or_insert(0) += 1;
                }
            }
        }

        eprintln!("biomes {counts:?} spawn {:?}", generated.spawn);
    }
}
