//! Builds the End's floating terrain from a shared three-dimensional density grid.

use crate::chunk::Chunk;
use crate::noise::{Perlin, Simplex};
use crate::random::JavaRandom;

const WIDTH: usize = 16;
const STEP: usize = 4;
const SIDE: usize = WIDTH / STEP + 1;
const MIN_Y: i32 = 0;
const TOP_Y: i32 = 104;
const LEVELS: usize = (TOP_Y - MIN_Y) as usize / STEP + 1;

const MAIN_RADIUS: f64 = 90.0;
const OUTER_START: f64 = 1024.0;
const ISLAND_SPACING: i32 = 64;
const OUTER_REACH: i32 = 288;

struct Island {
    x: f64,
    z: f64,
    radius_x: f64,
    radius_z: f64,
    level: f64,
    thickness: f64,
}

impl Island {
    fn density(&self, x: f64, y: f64, z: f64, roughness: f64, shoreline: f64, cliff_detail: f64) -> f64 {
        let dx = (x - self.x) / self.radius_x;
        let dz = (z - self.z) / self.radius_z;
        let small = self.thickness < 0.5;
        let distance = dx * dx + dz * dz;
        let rim = if small { 0.0 } else { ((distance - 0.56) / 0.44).clamp(0.0, 1.0) };
        let distance = distance + shoreline * rim;
        let offset = (y - self.level) / self.thickness;
        let edge = 1.0 - distance;

        if offset < 0.0 {
            let underside = 1.0 - (distance - 0.42).max(0.0) / 0.58;
            let depth = if small { 18.0 } else { 38.0 };
            let detail = if small { roughness } else { (roughness + cliff_detail * rim) * (-offset / 12.0).min(1.0) };

            return edge.min(underside + offset / depth) + detail;
        }

        let shelf = 1.0 - (distance - 0.24).max(0.0) / 0.76;
        let cap = edge.min(shelf - offset / 9.0);
        cap + if small { roughness } else { 0.0 }
    }
}

fn index(x: usize, y: usize, z: usize) -> usize {
    (x * SIDE + z) * LEVELS + y
}

fn lerp(a: f64, b: f64, t: f64) -> f64 {
    a + (b - a) * t
}

fn sample(grid: &[f64], x: usize, y: usize, z: usize) -> f64 {
    let (gx, gy, gz) = (x / STEP, y / STEP, z / STEP);
    let (u, v, w) = ((x % STEP) as f64, (y % STEP) as f64, (z % STEP) as f64);
    let column = |dx, dz| {
        lerp(grid[index(gx + dx, gy, gz + dz)], grid[index(gx + dx, gy + 1, gz + dz)], v / STEP as f64)
    };

    let near = lerp(column(0, 0), column(1, 0), u / STEP as f64);
    let far = lerp(column(0, 1), column(1, 1), u / STEP as f64);
    lerp(near, far, w / STEP as f64)
}

fn outer_islands(seed: i64, noise: &Simplex, base_x: i32, base_z: i32, shapes: &mut Vec<Island>) {
    if (base_x as f64).hypot(base_z as f64) < OUTER_START - 208.0 {
        return;
    }

    let width = WIDTH as i32;
    let mut satellites = Vec::new();

    for gx in (base_x - OUTER_REACH).div_euclid(ISLAND_SPACING)..=(base_x + width + OUTER_REACH).div_euclid(ISLAND_SPACING) {
        for gz in (base_z - OUTER_REACH).div_euclid(ISLAND_SPACING)..=(base_z + width + OUTER_REACH).div_euclid(ISLAND_SPACING) {
            let cell_seed = seed ^ (gx as i64).wrapping_mul(728421611963) ^ (gz as i64).wrapping_mul(163921667481);
            let mut random = JavaRandom::new(cell_seed);
            let x = (gx * ISLAND_SPACING) as f64 + random.next_double() * 48.0;
            let z = (gz * ISLAND_SPACING) as f64 + random.next_double() * 48.0;

            if x.hypot(z) < OUTER_START {
                continue;
            }

            let group = noise.sample(x / 292.0, z / 292.0);
            let placement = random.next_double();
            let large = group > -0.52 && placement < 0.30 + group * 0.24;

            if !large && (group < -0.55 || placement > 0.60) {
                continue;
            }

            let size = random.next_double();
            let radius_x = if large { 36.0 + size * size * 78.0 } else { 9.0 + size * 16.0 };
            let radius_z = radius_x * (0.64 + random.next_double() * 0.68);
            let level = if large { 52.0 + group * 3.5 } else { 47.0 + random.next_double() * 16.0 };
            let thickness = if large { 0.70 + size * 0.32 } else { 0.25 + size * 0.24 };

            let island = Island { x, z, radius_x, radius_z, level, thickness };

            if large {
                shapes.push(island);
            } else {
                satellites.push(island);
            }
        }
    }

    satellites.retain(|island| {
        shapes.iter().all(|large| {
            let dx = (island.x - large.x) / (large.radius_x + island.radius_x + 12.0);
            let dz = (island.z - large.z) / (large.radius_z + island.radius_z + 12.0);
            dx * dx + dz * dz > 1.0
        })
    });
    shapes.extend(satellites);
}

fn noise_grid(random: &mut JavaRandom, base_x: i32, base_z: i32, horizontal: f64, vertical: f64) -> [f64; SIDE * SIDE * LEVELS] {
    let mut grid = [0.0; SIDE * SIDE * LEVELS];

    for frequency in [1.0, 2.0] {
        let xz = frequency / horizontal;
        let y = frequency / vertical;
        Perlin::new(random).add_stepped(
            &mut grid, base_x as f64 * xz, MIN_Y as f64 * y, base_z as f64 * xz,
            SIDE, LEVELS, SIDE, STEP as f64 * xz, STEP as f64 * y, STEP as f64 * xz, frequency.powi(2),
        );
    }

    grid
}

fn fill_terrain(chunk: &mut Chunk, seed: i64, base_x: i32, base_z: i32, end_stone: u32) {
    let mut random = JavaRandom::new(seed);
    let islands = Simplex::new(&mut random);
    let relief = Simplex::new(&mut random);
    let mut shapes = Vec::new();
    let width = WIDTH as i32;
    let main = base_x <= 80 && base_x + width >= -144 && base_z <= 144 && base_z + width >= -144;

    if main {
        shapes.push(Island { x: -21.0, z: 0.0, radius_x: MAIN_RADIUS, radius_z: 105.0, level: 53.0, thickness: 1.0 });
    }

    outer_islands(seed, &islands, base_x, base_z, &mut shapes);

    if shapes.is_empty() {
        return;
    }

    let mut grid = [0.0; SIDE * SIDE * LEVELS];
    let low = noise_grid(&mut random, base_x, base_z, 64.0, 36.0);
    let high = noise_grid(&mut random, base_x, base_z, 28.0, 18.0);
    let selector = noise_grid(&mut random, base_x, base_z, 112.0, 76.0);

    for gx in 0..SIDE {
        for gz in 0..SIDE {
            let x = (base_x + (gx * STEP) as i32) as f64;
            let z = (base_z + (gz * STEP) as i32) as f64;
            let wx = x + relief.sample(x / 39.0, z / 39.0) * 3.5;
            let wz = z + relief.sample(x / 35.0 + 13.0, z / 35.0 - 17.0) * 7.0;
            let coast = if main { ((80.0 - x) / 16.0).clamp(0.0, 1.0) } else { 1.0 };
            let shoreline = coast * (
                relief.sample(x / 21.0 + 31.0, z / 21.0 - 19.0) * 0.16
                    + islands.sample(x / 9.0 - 37.0, z / 9.0 + 11.0) * 0.06
            );

            for gy in 0..LEVELS {
                let y = (MIN_Y + (gy * STEP) as i32) as f64;
                let i = index(gx, gy, gz);
                let mix = (0.5 + selector[i] * 0.8).clamp(0.0, 1.0);
                let roughness = lerp(low[i], high[i], mix).clamp(-1.0, 1.0) * 0.18;
                let cliff_detail = roughness * coast * 1.1;
                grid[i] = shapes.iter()
                    .map(|island| island.density(wx, y, wz, roughness, shoreline, cliff_detail))
                    .fold(f64::NEG_INFINITY, f64::max);
            }
        }
    }

    for x in 0..WIDTH {
        for z in 0..WIDTH {
            for y in MIN_Y..TOP_Y {
                if sample(&grid, x, (y - MIN_Y) as usize, z) > 0.0 {
                    chunk.set_block(x, y, z, 0, end_stone);
                }
            }
        }
    }
}

pub fn generate(seed: i64, chunk_x: i32, chunk_z: i32, biome: u32, states: [u32; 3], platform: [i32; 4]) -> Chunk {
    let [air, end_stone, obsidian] = states;
    let [platform_x, platform_y, platform_z, platform_radius] = platform;
    let mut chunk = Chunk::new(air, biome);
    let (base_x, base_z) = (chunk_x * WIDTH as i32, chunk_z * WIDTH as i32);

    fill_terrain(&mut chunk, seed, base_x, base_z, end_stone);

    for x in 0..WIDTH {
        for z in 0..WIDTH {
            if (base_x + x as i32 - platform_x).abs() <= platform_radius && (base_z + z as i32 - platform_z).abs() <= platform_radius {
                chunk.set_block(x, platform_y, z, 0, obsidian);
            }
        }
    }

    chunk
}