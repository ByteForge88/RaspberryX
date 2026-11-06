//! Caves and ravines, carved into a chunk by every tunnel that starts within 8 chunks of it.
//! Floats where the game has floats, its sine table where it has that, so tunnels bend the
//! same way.

use std::f32::consts::PI;

use super::terrain::{Terrain, HEIGHT};
use super::{cos, floor, sin};
use crate::generator::blocks::{self, Block};
use crate::random::JavaRandom;

const RANGE: i32 = 8;

/// Seeds a random for each chunk in range the way MapGenBase does and hands it over.
fn each_start(seed: i64, chunk_x: i32, chunk_z: i32, mut start: impl FnMut(&mut JavaRandom, i32, i32)) {
    let mut random = JavaRandom::new(seed);
    let a = random.next_long();
    let b = random.next_long();

    for x in chunk_x - RANGE..=chunk_x + RANGE {
        for z in chunk_z - RANGE..=chunk_z + RANGE {
            random.set_seed((x as i64).wrapping_mul(a) ^ (z as i64).wrapping_mul(b) ^ seed);
            start(&mut random, x, z);
        }
    }
}

/// The surface block a cave puts back on dirt it opens up from above. The game takes the
/// block's plain state, which makes red sand ordinary sand.
fn top_block(terrain: &Terrain, x: i32, z: i32) -> Block {
    match terrain.biome(x, z).top {
        blocks::RED_SAND => blocks::SAND,
        top => top,
    }
}

fn is_dirt(block: Block) -> bool {
    matches!(block, blocks::DIRT | blocks::COARSE_DIRT | blocks::PODZOL)
}

/// Whether the box a tunnel step would carve touches water, which stops that step.
fn hits_water(terrain: &Terrain, min_x: i32, max_x: i32, min_y: i32, max_y: i32, min_z: i32, max_z: i32) -> bool {
    for x in min_x..max_x {
        for z in min_z..max_z {
            let mut y = max_y + 1;

            while y >= min_y - 1 {
                if (0..HEIGHT).contains(&y) {
                    if terrain.get(x, y, z) == blocks::WATER {
                        return true;
                    }

                    //inside the box only the shell needs looking at
                    if y != min_y - 1 && x != min_x && x != max_x - 1 && z != min_z && z != max_z - 1 {
                        y = min_y;
                    }
                }

                y -= 1;
            }
        }
    }

    false
}

/// The step's box in the chunk, clamped: x, y and z bounds as the game computes them.
fn bounds(chunk_x: i32, chunk_z: i32, x: f64, y: f64, z: f64, radius: f64, vertical: f64) -> (i32, i32, i32, i32, i32, i32) {
    let min_x = (floor(x - radius) - chunk_x * 16 - 1).max(0);
    let max_x = (floor(x + radius) - chunk_x * 16 + 1).min(16);
    let min_y = (floor(y - vertical) - 1).max(1);
    let max_y = (floor(y + vertical) + 1).min(248);
    let min_z = (floor(z - radius) - chunk_z * 16 - 1).max(0);
    let max_z = (floor(z + radius) - chunk_z * 16 + 1).min(16);
    (min_x, max_x, min_y, max_y, min_z, max_z)
}

fn cave_carves(block: Block, above: Block) -> bool {
    matches!(
        block,
        blocks::STONE
            | blocks::DIRT
            | blocks::COARSE_DIRT
            | blocks::PODZOL
            | blocks::GRASS_BLOCK
            | blocks::TERRACOTTA
            | blocks::WHITE_TERRACOTTA
            | blocks::ORANGE_TERRACOTTA
            | blocks::YELLOW_TERRACOTTA
            | blocks::BROWN_TERRACOTTA
            | blocks::RED_TERRACOTTA
            | blocks::LIGHT_GRAY_TERRACOTTA
            | blocks::SANDSTONE
            | blocks::RED_SANDSTONE
            | blocks::MYCELIUM
            | blocks::SNOW_LAYER
    ) || (matches!(block, blocks::SAND | blocks::RED_SAND | blocks::GRAVEL) && above != blocks::WATER)
}

pub fn caves(terrain: &mut Terrain, seed: i64, chunk_x: i32, chunk_z: i32) {
    each_start(seed, chunk_x, chunk_z, |random, start_x, start_z| {
        let mut count = {
            let a = random.next_int_bounded(15) + 1;
            let b = random.next_int_bounded(a) + 1;
            random.next_int_bounded(b)
        };

        if random.next_int_bounded(7) != 0 {
            count = 0;
        }

        for _ in 0..count {
            let x = (start_x * 16 + random.next_int_bounded(16)) as f64;
            let y = {
                let bound = random.next_int_bounded(120) + 8;
                random.next_int_bounded(bound) as f64
            };
            let z = (start_z * 16 + random.next_int_bounded(16)) as f64;
            let mut tunnels = 1;

            if random.next_int_bounded(4) == 0 {
                let room_seed = random.next_long();
                let size = 1.0 + random.next_float() * 6.0;
                tunnel(terrain, room_seed, chunk_x, chunk_z, x, y, z, size, 0.0, 0.0, -1, -1, 0.5);
                tunnels += random.next_int_bounded(4);
            }

            for _ in 0..tunnels {
                let yaw = random.next_float() * PI * 2.0;
                let pitch = (random.next_float() - 0.5) * 2.0 / 8.0;
                let mut width = random.next_float() * 2.0 + random.next_float();

                if random.next_int_bounded(10) == 0 {
                    width *= random.next_float() * random.next_float() * 3.0 + 1.0;
                }

                let tunnel_seed = random.next_long();
                tunnel(terrain, tunnel_seed, chunk_x, chunk_z, x, y, z, width, yaw, pitch, 0, 0, 1.0);
            }
        }
    });
}

#[allow(clippy::too_many_arguments)]
fn tunnel(terrain: &mut Terrain, seed: i64, chunk_x: i32, chunk_z: i32, mut x: f64, mut y: f64, mut z: f64, width: f32, mut yaw: f32, mut pitch: f32, mut step: i32, mut steps: i32, height_scale: f64) {
    let center_x = (chunk_x * 16 + 8) as f64;
    let center_z = (chunk_z * 16 + 8) as f64;
    let mut yaw_change = 0.0f32;
    let mut pitch_change = 0.0f32;
    let mut random = JavaRandom::new(seed);

    if steps <= 0 {
        let most = RANGE * 16 - 16;
        steps = most - random.next_int_bounded(most / 4);
    }

    let mut room = false;

    if step == -1 {
        step = steps / 2;
        room = true;
    }

    let branch_at = random.next_int_bounded(steps / 2) + steps / 4;
    let steep = random.next_int_bounded(6) == 0;

    while step < steps {
        let radius = 1.5 + (sin(step as f32 * PI / steps as f32) * width * 1.0) as f64;
        let vertical = radius * height_scale;
        let cos_pitch = cos(pitch);
        let sin_pitch = sin(pitch);
        x += (cos(yaw) * cos_pitch) as f64;
        y += sin_pitch as f64;
        z += (sin(yaw) * cos_pitch) as f64;

        if steep {
            pitch *= 0.92;
        } else {
            pitch *= 0.7;
        }

        pitch += pitch_change * 0.1;
        yaw += yaw_change * 0.1;
        pitch_change *= 0.9;
        yaw_change *= 0.75;
        pitch_change += (random.next_float() - random.next_float()) * random.next_float() * 2.0;
        yaw_change += (random.next_float() - random.next_float()) * random.next_float() * 4.0;

        if !room && step == branch_at && width > 1.0 && steps > 0 {
            let first = random.next_long();
            let first_width = random.next_float() * 0.5 + 0.5;
            tunnel(terrain, first, chunk_x, chunk_z, x, y, z, first_width, yaw - PI / 2.0, pitch / 3.0, step, steps, 1.0);
            let second = random.next_long();
            let second_width = random.next_float() * 0.5 + 0.5;
            tunnel(terrain, second, chunk_x, chunk_z, x, y, z, second_width, yaw + PI / 2.0, pitch / 3.0, step, steps, 1.0);
            return;
        }

        if room || random.next_int_bounded(4) != 0 {
            let dx = x - center_x;
            let dz = z - center_z;
            let remaining = (steps - step) as f64;
            let reach = (width + 2.0 + 16.0) as f64;

            if dx * dx + dz * dz - remaining * remaining > reach * reach {
                return;
            }

            if x >= center_x - 16.0 - radius * 2.0 && z >= center_z - 16.0 - radius * 2.0 && x <= center_x + 16.0 + radius * 2.0 && z <= center_z + 16.0 + radius * 2.0 {
                let (min_x, max_x, min_y, max_y, min_z, max_z) = bounds(chunk_x, chunk_z, x, y, z, radius, vertical);

                if !hits_water(terrain, min_x, max_x, min_y, max_y, min_z, max_z) {
                    for bx in min_x..max_x {
                        let fx = ((bx + chunk_x * 16) as f64 + 0.5 - x) / radius;

                        for bz in min_z..max_z {
                            let fz = ((bz + chunk_z * 16) as f64 + 0.5 - z) / radius;
                            let mut grass = false;

                            if fx * fx + fz * fz >= 1.0 {
                                continue;
                            }

                            let mut by = max_y;

                            while by > min_y {
                                let fy = ((by - 1) as f64 + 0.5 - y) / vertical;

                                if fy > -0.7 && fx * fx + fy * fy + fz * fz < 1.0 {
                                    let block = terrain.get(bx, by, bz);
                                    let above = if by + 1 < HEIGHT { terrain.get(bx, by + 1, bz) } else { blocks::AIR };

                                    if block == blocks::GRASS_BLOCK {
                                        grass = true;
                                    }

                                    if cave_carves(block, above) {
                                        if by - 1 < 10 {
                                            terrain.set(bx, by, bz, blocks::LAVA);
                                        } else {
                                            terrain.set(bx, by, bz, blocks::AIR);

                                            if above == blocks::SAND || above == blocks::RED_SAND {
                                                terrain.set(bx, by + 1, bz, if above == blocks::RED_SAND { blocks::RED_SANDSTONE } else { blocks::SANDSTONE });
                                            }

                                            if grass && is_dirt(terrain.get(bx, by - 1, bz)) {
                                                let top = top_block(terrain, bx, bz);
                                                terrain.set(bx, by - 1, bz, top);
                                            }
                                        }
                                    }
                                }

                                by -= 1;
                            }
                        }
                    }

                    if room {
                        break;
                    }
                }
            }
        }

        step += 1;
    }
}

pub fn ravines(terrain: &mut Terrain, seed: i64, chunk_x: i32, chunk_z: i32) {
    each_start(seed, chunk_x, chunk_z, |random, start_x, start_z| {
        if random.next_int_bounded(50) != 0 {
            return;
        }

        let x = (start_x * 16 + random.next_int_bounded(16)) as f64;
        let y = {
            let bound = random.next_int_bounded(40) + 8;
            (random.next_int_bounded(bound) + 20) as f64
        };
        let z = (start_z * 16 + random.next_int_bounded(16)) as f64;
        let yaw = random.next_float() * PI * 2.0;
        let pitch = (random.next_float() - 0.5) * 2.0 / 8.0;
        let width = (random.next_float() * 2.0 + random.next_float()) * 2.0;
        let ravine_seed = random.next_long();

        ravine(terrain, ravine_seed, chunk_x, chunk_z, x, y, z, width, yaw, pitch, 3.0);
    });
}

#[allow(clippy::too_many_arguments)]
fn ravine(terrain: &mut Terrain, seed: i64, chunk_x: i32, chunk_z: i32, mut x: f64, mut y: f64, mut z: f64, width: f32, mut yaw: f32, mut pitch: f32, height_scale: f64) {
    let mut random = JavaRandom::new(seed);
    let center_x = (chunk_x * 16 + 8) as f64;
    let center_z = (chunk_z * 16 + 8) as f64;
    let mut yaw_change = 0.0f32;
    let mut pitch_change = 0.0f32;

    let most = RANGE * 16 - 16;
    let steps = most - random.next_int_bounded(most / 4);
    let mut step = 0;

    //each height gets a width of its own, which roughens the walls
    let mut widths = [0.0f32; 256];
    let mut current = 1.0f32;

    for (y, slot) in widths.iter_mut().enumerate() {
        if y == 0 || random.next_int_bounded(3) == 0 {
            current = 1.0 + random.next_float() * random.next_float() * 1.0;
        }

        *slot = current * current;
    }

    while step < steps {
        let mut radius = 1.5 + (sin(step as f32 * PI / steps as f32) * width * 1.0) as f64;
        let mut vertical = radius * height_scale;
        radius *= random.next_float() as f64 * 0.25 + 0.75;
        vertical *= random.next_float() as f64 * 0.25 + 0.75;
        let cos_pitch = cos(pitch);
        let sin_pitch = sin(pitch);
        x += (cos(yaw) * cos_pitch) as f64;
        y += sin_pitch as f64;
        z += (sin(yaw) * cos_pitch) as f64;
        pitch *= 0.7;
        pitch += pitch_change * 0.05;
        yaw += yaw_change * 0.05;
        pitch_change *= 0.8;
        yaw_change *= 0.5;
        pitch_change += (random.next_float() - random.next_float()) * random.next_float() * 2.0;
        yaw_change += (random.next_float() - random.next_float()) * random.next_float() * 4.0;

        if random.next_int_bounded(4) != 0 {
            let dx = x - center_x;
            let dz = z - center_z;
            let remaining = (steps - step) as f64;
            let reach = (width + 2.0 + 16.0) as f64;

            if dx * dx + dz * dz - remaining * remaining > reach * reach {
                return;
            }

            if x >= center_x - 16.0 - radius * 2.0 && z >= center_z - 16.0 - radius * 2.0 && x <= center_x + 16.0 + radius * 2.0 && z <= center_z + 16.0 + radius * 2.0 {
                let (min_x, max_x, min_y, max_y, min_z, max_z) = bounds(chunk_x, chunk_z, x, y, z, radius, vertical);

                if !hits_water(terrain, min_x, max_x, min_y, max_y, min_z, max_z) {
                    for bx in min_x..max_x {
                        let fx = ((bx + chunk_x * 16) as f64 + 0.5 - x) / radius;

                        for bz in min_z..max_z {
                            let fz = ((bz + chunk_z * 16) as f64 + 0.5 - z) / radius;
                            let mut grass = false;

                            if fx * fx + fz * fz >= 1.0 {
                                continue;
                            }

                            let mut by = max_y;

                            while by > min_y {
                                let fy = ((by - 1) as f64 + 0.5 - y) / vertical;

                                if (fx * fx + fz * fz) * widths[(by - 1) as usize] as f64 + fy * fy / 6.0 < 1.0 {
                                    let block = terrain.get(bx, by, bz);

                                    if block == blocks::GRASS_BLOCK {
                                        grass = true;
                                    }

                                    if matches!(block, blocks::STONE | blocks::DIRT | blocks::COARSE_DIRT | blocks::PODZOL | blocks::GRASS_BLOCK) {
                                        if by - 1 < 10 {
                                            terrain.set(bx, by, bz, blocks::LAVA);
                                        } else {
                                            terrain.set(bx, by, bz, blocks::AIR);

                                            if grass && is_dirt(terrain.get(bx, by - 1, bz)) {
                                                let top = top_block(terrain, bx, bz);
                                                terrain.set(bx, by - 1, bz, top);
                                            }
                                        }
                                    }
                                }

                                by -= 1;
                            }
                        }
                    }
                }
            }
        }

        step += 1;
    }
}
