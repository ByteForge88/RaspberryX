//! Decoration the way 1.8 populates a chunk: lakes, then what the biome grows, ore first.
//! A chunk's decoration lands from 8 blocks in, so it reaches into the chunks east and south
//! of it, as in the game.

use std::f32::consts::PI;

use super::biomes::{id, LegacyBiome, Style};
use super::terrain::{fixed, temperature, SEA_LEVEL};
use super::trees::{self, Tree};
use super::world::{is_dirt, is_full, is_grass_or_dirt, is_liquid, is_solid, View};
use super::{cos, floor, sin};
use crate::generator::blocks::{self, Block};
use crate::random::JavaRandom;

fn is_stone(block: Block) -> bool {
    matches!(block, blocks::STONE | blocks::GRANITE | blocks::DIORITE | blocks::ANDESITE)
}

/// The random a chunk is decorated with, from the world seed and its position.
pub fn random(seed: i64, chunk_x: i32, chunk_z: i32) -> JavaRandom {
    let mut random = JavaRandom::new(seed);
    let a = random.next_long() / 2 * 2 + 1;
    let b = random.next_long() / 2 * 2 + 1;
    random.set_seed(((chunk_x as i64).wrapping_mul(a)).wrapping_add((chunk_z as i64).wrapping_mul(b)) ^ seed);
    random
}

pub fn populate(view: &mut View, seed: i64, chunk_x: i32, chunk_z: i32) {
    let (x, z) = (chunk_x * 16, chunk_z * 16);
    let biome = view.biome(x + 16, z + 16);
    let mut random = random(seed, chunk_x, chunk_z);

    if biome.id != id::DESERT && biome.id != id::DESERT_HILLS && random.next_int_bounded(4) == 0 {
        let lx = random.next_int_bounded(16) + 8;
        let ly = random.next_int_bounded(256);
        let lz = random.next_int_bounded(16) + 8;
        lake(view, &mut random, x + lx, ly, z + lz, blocks::WATER);
    }

    if random.next_int_bounded(8) == 0 {
        let lx = random.next_int_bounded(16) + 8;
        let ly = {
            let bound = random.next_int_bounded(248) + 8;
            random.next_int_bounded(bound)
        };
        let lz = random.next_int_bounded(16) + 8;

        if ly < SEA_LEVEL || random.next_int_bounded(10) == 0 {
            lake(view, &mut random, x + lx, ly, z + lz, blocks::LAVA);
        }
    }

    //dungeons come here in the game; they are left for when chests and spawners can be filled

    decorate_biome(view, &mut random, biome, x, z);
}

fn lake(view: &mut View, random: &mut JavaRandom, x: i32, mut y: i32, z: i32, liquid: Block) {
    let (x, z) = (x - 8, z - 8);

    while y > 5 && view.is_air(x, y, z) {
        y -= 1;
    }

    if y <= 4 {
        return;
    }

    y -= 4;
    let mut shape = [false; 2048];
    let blobs = random.next_int_bounded(4) + 4;

    for _ in 0..blobs {
        let sx = random.next_double() * 6.0 + 3.0;
        let sy = random.next_double() * 4.0 + 2.0;
        let sz = random.next_double() * 6.0 + 3.0;
        let cx = random.next_double() * (16.0 - sx - 2.0) + 1.0 + sx / 2.0;
        let cy = random.next_double() * (8.0 - sy - 4.0) + 2.0 + sy / 2.0;
        let cz = random.next_double() * (16.0 - sz - 2.0) + 1.0 + sz / 2.0;

        for l in 1..15 {
            for i1 in 1..15 {
                for j1 in 1..7 {
                    let dx = (l as f64 - cx) / (sx / 2.0);
                    let dy = (j1 as f64 - cy) / (sy / 2.0);
                    let dz = (i1 as f64 - cz) / (sz / 2.0);

                    if dx * dx + dy * dy + dz * dz < 1.0 {
                        shape[((l * 16 + i1) * 8 + j1) as usize] = true;
                    }
                }
            }
        }
    }

    let at = |l: i32, i: i32, k: i32| shape[((l * 16 + i) * 8 + k) as usize];
    let edge = |l: i32, i: i32, k: i32| {
        !at(l, i, k)
            && ((l < 15 && at(l + 1, i, k))
                || (l > 0 && at(l - 1, i, k))
                || (i < 15 && at(l, i + 1, k))
                || (i > 0 && at(l, i - 1, k))
                || (k < 7 && at(l, i, k + 1))
                || (k > 0 && at(l, i, k - 1)))
    };

    for l in 0..16 {
        for i in 0..16 {
            for k in 0..8 {
                if edge(l, i, k) {
                    let block = view.get(x + l, y + k, z + i);

                    if k >= 4 && is_liquid(block) {
                        return;
                    }

                    if k < 4 && !is_solid(block) && block != liquid {
                        return;
                    }
                }
            }
        }
    }

    for l in 0..16 {
        for i in 0..16 {
            for k in 0..8 {
                if at(l, i, k) {
                    view.set(x + l, y + k, z + i, if k >= 4 { blocks::AIR } else { liquid });
                }
            }
        }
    }

    for l in 0..16 {
        for i in 0..16 {
            for k in 4..8 {
                if at(l, i, k) && is_dirt(view.get(x + l, y + k - 1, z + i)) && view.height(x + l, z + i) <= y + k {
                    let top = if view.biome(x + l, z + i).top == blocks::MYCELIUM { blocks::MYCELIUM } else { blocks::GRASS_BLOCK };
                    view.set(x + l, y + k - 1, z + i, top);
                }
            }
        }
    }

    if liquid == blocks::LAVA {
        for l in 0..16 {
            for i in 0..16 {
                for k in 0..8 {
                    if edge(l, i, k) && (k < 4 || random.next_int_bounded(2) != 0) && is_solid(view.get(x + l, y + k, z + i)) {
                        view.set(x + l, y + k, z + i, blocks::STONE);
                    }
                }
            }
        }
    } else {
        for l in 0..16 {
            for i in 0..16 {
                if view.get(x + l, y + 4, z + i) == blocks::WATER && temperature(view.biome(x + l, z + i), x + l, y + 4, z + i) < 0.15 {
                    view.set(x + l, y + 4, z + i, blocks::ICE);
                }
            }
        }
    }
}

/// A vein of ore through stone, the game's WorldGenMinable.
fn ore(view: &mut View, random: &mut JavaRandom, x: i32, y: i32, z: i32, block: Block, size: i32) {
    let angle = random.next_float() * PI;
    let spread = size as f32 / 8.0;
    let x0 = ((x + 8) as f32 + sin(angle) * spread) as f64;
    let x1 = ((x + 8) as f32 - sin(angle) * spread) as f64;
    let z0 = ((z + 8) as f32 + cos(angle) * spread) as f64;
    let z1 = ((z + 8) as f32 - cos(angle) * spread) as f64;
    let y0 = (y + random.next_int_bounded(3) - 2) as f64;
    let y1 = (y + random.next_int_bounded(3) - 2) as f64;

    for i in 0..size {
        let t = i as f32 / size as f32;
        let cx = x0 + (x1 - x0) * t as f64;
        let cy = y0 + (y1 - y0) * t as f64;
        let cz = z0 + (z1 - z0) * t as f64;
        let thickness = random.next_double() * size as f64 / 16.0;
        let width = (sin(PI * t) + 1.0) as f64 * thickness + 1.0;
        let tall = (sin(PI * t) + 1.0) as f64 * thickness + 1.0;

        for bx in floor(cx - width / 2.0)..=floor(cx + width / 2.0) {
            let dx = (bx as f64 + 0.5 - cx) / (width / 2.0);

            if dx * dx >= 1.0 {
                continue;
            }

            for by in floor(cy - tall / 2.0)..=floor(cy + tall / 2.0) {
                let dy = (by as f64 + 0.5 - cy) / (tall / 2.0);

                if dx * dx + dy * dy >= 1.0 {
                    continue;
                }

                for bz in floor(cz - width / 2.0)..=floor(cz + width / 2.0) {
                    let dz = (bz as f64 + 0.5 - cz) / (width / 2.0);

                    if dx * dx + dy * dy + dz * dz < 1.0 && is_stone(view.get(bx, by, bz)) {
                        view.set(bx, by, bz, block);
                    }
                }
            }
        }
    }
}

fn ores_between(view: &mut View, random: &mut JavaRandom, x: i32, z: i32, count: i32, block: Block, size: i32, min: i32, max: i32) {
    for _ in 0..count {
        let ox = random.next_int_bounded(16);
        let oy = random.next_int_bounded(max - min) + min;
        let oz = random.next_int_bounded(16);
        ore(view, random, x + ox, oy, z + oz, block, size);
    }
}

fn ores(view: &mut View, random: &mut JavaRandom, x: i32, z: i32, biome: &LegacyBiome) {
    ores_between(view, random, x, z, 10, blocks::DIRT, 33, 0, 256);
    ores_between(view, random, x, z, 8, blocks::GRAVEL, 33, 0, 256);
    ores_between(view, random, x, z, 10, blocks::DIORITE, 33, 0, 80);
    ores_between(view, random, x, z, 10, blocks::GRANITE, 33, 0, 80);
    ores_between(view, random, x, z, 10, blocks::ANDESITE, 33, 0, 80);
    ores_between(view, random, x, z, 20, blocks::COAL_ORE, 17, 0, 128);
    ores_between(view, random, x, z, 20, blocks::IRON_ORE, 9, 0, 64);
    ores_between(view, random, x, z, 2, blocks::GOLD_ORE, 9, 0, 32);
    ores_between(view, random, x, z, 8, blocks::REDSTONE_ORE, 8, 0, 16);
    ores_between(view, random, x, z, 1, blocks::DIAMOND_ORE, 8, 0, 16);

    let ox = random.next_int_bounded(16);
    let oy = random.next_int_bounded(16) + random.next_int_bounded(16);
    let oz = random.next_int_bounded(16);
    ore(view, random, x + ox, oy, z + oz, blocks::LAPIS_ORE, 7);

    //mesas carry extra gold up to 80
    if biome.style == Style::Mesa {
        ores_between(view, random, x, z, 20, blocks::GOLD_ORE, 9, 32, 80);
    }
}

/// A disc of sand, gravel or clay under water, replacing dirt.
fn disc(view: &mut View, random: &mut JavaRandom, x: i32, y: i32, z: i32, block: Block, radius: i32, half_height: i32) {
    if view.get(x, y, z) != blocks::WATER {
        return;
    }

    let size = random.next_int_bounded(radius - 2) + 2;

    for bx in x - size..=x + size {
        for bz in z - size..=z + size {
            let (dx, dz) = (bx - x, bz - z);

            if dx * dx + dz * dz <= size * size {
                for by in y - half_height..=y + half_height {
                    let current = view.get(bx, by, bz);
                    let replaces = if block == blocks::CLAY { is_dirt(current) || current == blocks::CLAY } else { is_dirt(current) || current == blocks::GRASS_BLOCK };

                    if replaces {
                        view.set(bx, by, bz, block);
                    }
                }
            }
        }
    }
}

const FLOWER_TYPES: [Block; 10] = [
    blocks::DANDELION,
    blocks::POPPY,
    blocks::BLUE_ORCHID,
    blocks::ALLIUM,
    blocks::AZURE_BLUET,
    blocks::RED_TULIP,
    blocks::ORANGE_TULIP,
    blocks::WHITE_TULIP,
    blocks::PINK_TULIP,
    blocks::OXEYE_DAISY,
];

fn pick_flower(biome: &LegacyBiome, random: &mut JavaRandom, x: i32, z: i32) -> Block {
    match biome.style {
        Style::Plains { .. } => {
            let noise = fixed().grass_color.sample(x as f64 / 200.0, z as f64 / 200.0);

            if noise < -0.8 {
                [blocks::ORANGE_TULIP, blocks::RED_TULIP, blocks::PINK_TULIP, blocks::WHITE_TULIP][random.next_int_bounded(4) as usize]
            } else if random.next_int_bounded(3) > 0 {
                [blocks::POPPY, blocks::AZURE_BLUET, blocks::OXEYE_DAISY][random.next_int_bounded(3) as usize]
            } else {
                blocks::DANDELION
            }
        }
        Style::Forest { kind: 1 } => {
            let noise = ((1.0 + fixed().grass_color.sample(x as f64 / 48.0, z as f64 / 48.0)) / 2.0).clamp(0.0, 0.9999);
            let flower = FLOWER_TYPES[(noise * FLOWER_TYPES.len() as f64) as usize];
            if flower == blocks::BLUE_ORCHID { blocks::POPPY } else { flower }
        }
        Style::Swamp => blocks::BLUE_ORCHID,
        _ => {
            if random.next_int_bounded(3) > 0 { blocks::DANDELION } else { blocks::POPPY }
        }
    }
}

fn spread(random: &mut JavaRandom, x: i32, y: i32, z: i32) -> (i32, i32, i32) {
    let dx = random.next_int_bounded(8) - random.next_int_bounded(8);
    let dy = random.next_int_bounded(4) - random.next_int_bounded(4);
    let dz = random.next_int_bounded(8) - random.next_int_bounded(8);
    (x + dx, y + dy, z + dz)
}

fn flowers(view: &mut View, random: &mut JavaRandom, x: i32, y: i32, z: i32, flower: Block) {
    for _ in 0..64 {
        let (bx, by, bz) = spread(random, x, y, z);

        if view.is_air(bx, by, bz) && by < 255 && is_grass_or_dirt(view.get(bx, by - 1, bz)) && view.is_lit(bx, by, bz) {
            view.set(bx, by, bz, flower);
        }
    }
}

/// Settles down through air and leaves first, like grass and dead bushes do.
fn settle(view: &View, x: i32, mut y: i32, z: i32) -> i32 {
    loop {
        let block = view.get(x, y, z);

        if !(block == blocks::AIR || blocks::is_leaves(block)) || y <= 0 {
            return y;
        }

        y -= 1;
    }
}

fn tall_grass(view: &mut View, random: &mut JavaRandom, x: i32, y: i32, z: i32, plant: Block) {
    let y = settle(view, x, y, z);

    for _ in 0..128 {
        let (bx, by, bz) = spread(random, x, y, z);

        if view.is_air(bx, by, bz) && is_grass_or_dirt(view.get(bx, by - 1, bz)) && view.is_lit(bx, by, bz) {
            view.set(bx, by, bz, plant);
        }
    }
}

fn dead_bushes(view: &mut View, random: &mut JavaRandom, x: i32, y: i32, z: i32) {
    let y = settle(view, x, y, z);

    for _ in 0..4 {
        let (bx, by, bz) = spread(random, x, y, z);
        let below = view.get(bx, by - 1, bz);
        let ground = matches!(below, blocks::SAND | blocks::RED_SAND) || is_dirt(below) || (blocks::TERRACOTTA..=blocks::LIGHT_GRAY_TERRACOTTA).contains(&below);

        if view.is_air(bx, by, bz) && ground && view.is_lit(bx, by, bz) {
            view.set(bx, by, bz, blocks::DEAD_BUSH);
        }
    }
}

fn double_plant(view: &mut View, random: &mut JavaRandom, x: i32, y: i32, z: i32, (bottom, top): (Block, Block)) -> bool {
    let mut placed = false;

    for _ in 0..64 {
        let (bx, by, bz) = spread(random, x, y, z);

        if view.is_air(bx, by, bz) && by < 254 && is_grass_or_dirt(view.get(bx, by - 1, bz)) && view.is_air(bx, by + 1, bz) {
            view.set(bx, by, bz, bottom);
            view.set(bx, by + 1, bz, top);
            placed = true;
        }
    }

    placed
}

const SUNFLOWER: (Block, Block) = (blocks::SUNFLOWER_BOTTOM, blocks::SUNFLOWER_TOP);
const LILAC: (Block, Block) = (blocks::LILAC_BOTTOM, blocks::LILAC_TOP);
const DOUBLE_GRASS: (Block, Block) = (blocks::TALL_GRASS_BOTTOM, blocks::TALL_GRASS_TOP);
const LARGE_FERN: (Block, Block) = (blocks::LARGE_FERN_BOTTOM, blocks::LARGE_FERN_TOP);
const ROSE_BUSH: (Block, Block) = (blocks::ROSE_BUSH_BOTTOM, blocks::ROSE_BUSH_TOP);
const PEONY: (Block, Block) = (blocks::PEONY_BOTTOM, blocks::PEONY_TOP);

fn lily_pads(view: &mut View, random: &mut JavaRandom, x: i32, y: i32, z: i32) {
    for _ in 0..10 {
        let bx = x + random.next_int_bounded(8) - random.next_int_bounded(8);
        let by = y + random.next_int_bounded(4) - random.next_int_bounded(4);
        let bz = z + random.next_int_bounded(8) - random.next_int_bounded(8);

        if view.is_air(bx, by, bz) && view.get(bx, by - 1, bz) == blocks::WATER {
            view.set(bx, by, bz, blocks::LILY_PAD);
        }
    }
}

fn mushrooms(view: &mut View, random: &mut JavaRandom, x: i32, y: i32, z: i32, mushroom: Block) {
    for _ in 0..64 {
        let (bx, by, bz) = spread(random, x, y, z);

        if !view.is_air(bx, by, bz) || by >= 255 || !(0..256).contains(&by) {
            continue;
        }

        let below = view.get(bx, by - 1, bz);
        let stays = below == blocks::MYCELIUM || below == blocks::PODZOL || (is_full(below) && !view.is_open(bx, by, bz));

        if stays {
            view.set(bx, by, bz, mushroom);
        }
    }
}

fn next_to_water(view: &View, x: i32, y: i32, z: i32) -> bool {
    [(-1, 0), (1, 0), (0, -1), (0, 1)].iter().any(|(dx, dz)| view.get(x + dx, y, z + dz) == blocks::WATER)
}

fn reeds(view: &mut View, random: &mut JavaRandom, x: i32, y: i32, z: i32) {
    for _ in 0..20 {
        let bx = x + random.next_int_bounded(4) - random.next_int_bounded(4);
        let bz = z + random.next_int_bounded(4) - random.next_int_bounded(4);

        if view.is_air(bx, y, bz) && next_to_water(view, bx, y - 1, bz) {
            let height = {
                let bound = random.next_int_bounded(3) + 1;
                2 + random.next_int_bounded(bound)
            };
            let below = view.get(bx, y - 1, bz);
            let stays = below == blocks::SUGAR_CANE || ((is_grass_or_dirt(below) || below == blocks::SAND || below == blocks::RED_SAND) && next_to_water(view, bx, y - 1, bz));

            for k in 0..height {
                if stays {
                    view.set(bx, y + k, bz, blocks::SUGAR_CANE);
                }
            }
        }
    }
}

fn pumpkins(view: &mut View, random: &mut JavaRandom, x: i32, y: i32, z: i32) {
    for _ in 0..64 {
        let (bx, by, bz) = spread(random, x, y, z);

        if view.is_air(bx, by, bz) && view.get(bx, by - 1, bz) == blocks::GRASS_BLOCK {
            let _facing = random.next_int_bounded(4);
            view.set(bx, by, bz, blocks::PUMPKIN);
        }
    }
}

fn cactus_stays(view: &View, x: i32, y: i32, z: i32) -> bool {
    let sides_free = [(-1, 0), (1, 0), (0, -1), (0, 1)].iter().all(|(dx, dz)| !is_solid(view.get(x + dx, y, z + dz)));
    let below = view.get(x, y - 1, z);
    sides_free && (below == blocks::CACTUS || below == blocks::SAND || below == blocks::RED_SAND)
}

fn cacti(view: &mut View, random: &mut JavaRandom, x: i32, y: i32, z: i32) {
    for _ in 0..10 {
        let (bx, by, bz) = spread(random, x, y, z);

        if view.is_air(bx, by, bz) {
            let height = {
                let bound = random.next_int_bounded(3) + 1;
                1 + random.next_int_bounded(bound)
            };

            for k in 0..height {
                if cactus_stays(view, bx, by + k, bz) {
                    view.set(bx, by + k, bz, blocks::CACTUS);
                }
            }
        }
    }
}

/// A spring: a liquid source in a wall of stone with one side open.
fn spring(view: &mut View, x: i32, y: i32, z: i32, liquid: Block) {
    if !is_stone(view.get(x, y + 1, z)) || !is_stone(view.get(x, y - 1, z)) {
        return;
    }

    let here = view.get(x, y, z);

    if here != blocks::AIR && !is_stone(here) {
        return;
    }

    let sides = [(-1, 0), (1, 0), (0, -1), (0, 1)];
    let stone = sides.iter().filter(|(dx, dz)| is_stone(view.get(x + dx, y, z + dz))).count();
    let air = sides.iter().filter(|(dx, dz)| view.is_air(x + dx, y, z + dz)).count();

    if stone == 3 && air == 1 {
        view.set(x, y, z, liquid);
    }
}

fn melons(view: &mut View, random: &mut JavaRandom, x: i32, y: i32, z: i32) {
    for _ in 0..64 {
        let (bx, by, bz) = spread(random, x, y, z);
        let here = view.get(bx, by, bz);
        let replaceable = here == blocks::AIR || here == blocks::WATER || matches!(here, blocks::SHORT_GRASS | blocks::FERN | blocks::DEAD_BUSH | blocks::SNOW_LAYER) || blocks::is_vine(here);

        if replaceable && view.get(bx, by - 1, bz) == blocks::GRASS_BLOCK {
            view.set(bx, by, bz, blocks::MELON);
        }
    }
}

fn hanging_vines(view: &mut View, random: &mut JavaRandom, x: i32, mut y: i32, z: i32) {
    let (mut x, mut z) = (x, z);

    while y < 128 {
        if view.is_air(x, y, z) {
            //north, east, south, west: the vine hangs on whichever side has a full block
            for (dx, dz, vine) in [(0, 1, blocks::VINE_SOUTH), (-1, 0, blocks::VINE_WEST), (0, -1, blocks::VINE_NORTH), (1, 0, blocks::VINE_EAST)] {
                if is_full(view.get(x + dx, y, z + dz)) {
                    view.set(x, y, z, vine);
                    break;
                }
            }
        } else {
            x += random.next_int_bounded(4) - random.next_int_bounded(4);
            z += random.next_int_bounded(4) - random.next_int_bounded(4);
        }

        y += 1;
    }
}

fn boulder(view: &mut View, random: &mut JavaRandom, x: i32, mut y: i32, z: i32) {
    let (mut x, mut z) = (x, z);

    loop {
        if y > 3 {
            let below = view.get(x, y - 1, z);

            if below == blocks::AIR || !(is_grass_or_dirt(below) || is_stone(below)) {
                y -= 1;
                continue;
            }
        }

        if y <= 3 {
            return;
        }

        for _ in 0..3 {
            let j = random.next_int_bounded(2);
            let k = random.next_int_bounded(2);
            let l = random.next_int_bounded(2);
            let radius = (j + k + l) as f32 * 0.333 + 0.5;

            for bx in x - j..=x + j {
                for by in y - k..=y + k {
                    for bz in z - l..=z + l {
                        let (dx, dy, dz) = ((bx - x) as f64, (by - y) as f64, (bz - z) as f64);

                        if dx * dx + dy * dy + dz * dz <= (radius * radius) as f64 {
                            view.set(bx, by, bz, blocks::MOSSY_COBBLESTONE);
                        }
                    }
                }
            }

            x += -1 + random.next_int_bounded(2);
            y -= random.next_int_bounded(2);
            z += -1 + random.next_int_bounded(2);
        }

        return;
    }
}

fn ice_spike(view: &mut View, random: &mut JavaRandom, x: i32, mut y: i32, z: i32) {
    while view.is_air(x, y, z) && y > 2 {
        y -= 1;
    }

    if view.get(x, y, z) != blocks::SNOW {
        return;
    }

    y += random.next_int_bounded(4);
    let height = random.next_int_bounded(4) + 7;
    let width = height / 4 + random.next_int_bounded(2);

    if width > 1 && random.next_int_bounded(60) == 0 {
        y += 10 + random.next_int_bounded(30);
    }

    let melts = |block: Block| block == blocks::AIR || is_dirt(block) || block == blocks::SNOW || block == blocks::ICE;

    for k in 0..height {
        let size = (1.0 - k as f32 / height as f32) * width as f32;
        let reach = size.ceil() as i32;

        for i in -reach..=reach {
            let fi = i.abs() as f32 - 0.25;

            for j in -reach..=reach {
                let fj = j.abs() as f32 - 0.25;

                if ((i == 0 && j == 0) || fi * fi + fj * fj <= size * size) && ((i != -reach && i != reach && j != -reach && j != reach) || random.next_float() <= 0.75) {
                    if melts(view.get(x + i, y + k, z + j)) {
                        view.set(x + i, y + k, z + j, blocks::PACKED_ICE);
                    }

                    if k != 0 && reach > 1 && melts(view.get(x + i, y - k, z + j)) {
                        view.set(x + i, y - k, z + j, blocks::PACKED_ICE);
                    }
                }
            }
        }
    }

    let foot = (width - 1).clamp(0, 1);

    for i in -foot..=foot {
        for j in -foot..=foot {
            let mut by = y - 1;
            let mut left = if i.abs() == 1 && j.abs() == 1 { random.next_int_bounded(5) } else { 50 };

            while by > 50 {
                let block = view.get(x + i, by, z + j);

                if !(melts(block) || block == blocks::PACKED_ICE) {
                    break;
                }

                view.set(x + i, by, z + j, blocks::PACKED_ICE);
                by -= 1;
                left -= 1;

                if left <= 0 {
                    by -= random.next_int_bounded(5) + 1;
                    left = random.next_int_bounded(5);
                }
            }
        }
    }
}

fn ice_path(view: &mut View, random: &mut JavaRandom, x: i32, mut y: i32, z: i32) {
    while view.is_air(x, y, z) && y > 2 {
        y -= 1;
    }

    if view.get(x, y, z) != blocks::SNOW {
        return;
    }

    let size = random.next_int_bounded(2) + 2;

    for bx in x - size..=x + size {
        for bz in z - size..=z + size {
            if (bx - x) * (bx - x) + (bz - z) * (bz - z) <= size * size {
                for by in y - 1..=y + 1 {
                    let block = view.get(bx, by, bz);

                    if is_dirt(block) || block == blocks::SNOW || block == blocks::ICE {
                        view.set(bx, by, bz, blocks::PACKED_ICE);
                    }
                }
            }
        }
    }
}

/// The tree a biome picks, drawing from the random like the game does.
fn pick_tree(biome: &LegacyBiome, random: &mut JavaRandom) -> Tree {
    let default = |random: &mut JavaRandom| if random.next_int_bounded(10) == 0 { Tree::BigOak } else { Tree::Oak };
    let style = match biome.style {
        Style::Mutated(base) => super::biomes::of(base).style,
        Style::RoofedMutated => Style::Forest { kind: 3 },
        style => style,
    };

    match style {
        Style::Forest { kind } => {
            if kind == 3 && random.next_int_bounded(3) > 0 {
                Tree::DarkOak
            } else if kind != 2 && random.next_int_bounded(5) != 0 {
                Tree::Oak
            } else {
                Tree::Birch
            }
        }
        Style::TallBirch => {
            if random.next_bool() { Tree::TallBirch } else { Tree::Birch }
        }
        Style::Taiga { kind } => {
            if (kind == 1 || kind == 2) && random.next_int_bounded(3) == 0 {
                if kind != 2 && random.next_int_bounded(13) != 0 { Tree::MegaPine } else { Tree::MegaSpruce }
            } else if random.next_int_bounded(3) == 0 {
                Tree::Pine
            } else {
                Tree::Spruce
            }
        }
        Style::Swamp => Tree::Swamp,
        Style::Hills => {
            if random.next_int_bounded(3) > 0 { Tree::Spruce } else { default(random) }
        }
        Style::Jungle { edge } => {
            if random.next_int_bounded(10) == 0 {
                Tree::BigOak
            } else if random.next_int_bounded(2) == 0 {
                Tree::Shrub
            } else if !edge && random.next_int_bounded(3) == 0 {
                Tree::MegaJungle
            } else {
                Tree::Small { height: 4 + random.next_int_bounded(7), log: blocks::JUNGLE_LOG, leaves: blocks::JUNGLE_LEAVES, jungle: true }
            }
        }
        Style::Savanna | Style::SavannaMutated => {
            if random.next_int_bounded(5) > 0 { Tree::Acacia } else { Tree::Oak }
        }
        Style::Snow { .. } => Tree::Spruce,
        Style::Mesa => Tree::Oak,
        _ => default(random),
    }
}

fn grass_for(biome: &LegacyBiome, random: &mut JavaRandom) -> Block {
    let style = match biome.style {
        Style::Mutated(base) => super::biomes::of(base).style,
        style => style,
    };

    match style {
        Style::Taiga { .. } => {
            if random.next_int_bounded(5) > 0 { blocks::FERN } else { blocks::SHORT_GRASS }
        }
        Style::Jungle { .. } => {
            if random.next_int_bounded(4) == 0 { blocks::FERN } else { blocks::SHORT_GRASS }
        }
        _ => blocks::SHORT_GRASS,
    }
}

fn spot(random: &mut JavaRandom) -> (i32, i32) {
    let x = random.next_int_bounded(16) + 8;
    let z = random.next_int_bounded(16) + 8;
    (x, z)
}

fn tree_at(view: &mut View, random: &mut JavaRandom, tree: Tree, x: i32, z: i32) {
    let y = view.height(x, z);

    if trees::grow(view, random, tree, x, y, z) {
        trees::after(view, random, tree, x, y, z);
    }
}

/// Double plants at random heights up to 32 above the ground, `tries` times.
fn double_plants(view: &mut View, random: &mut JavaRandom, x: i32, z: i32, tries: i32, plant: (Block, Block)) {
    for _ in 0..tries {
        let (ox, oz) = spot(random);
        let y = random.next_int_bounded(view.height(x + ox, z + oz) + 32);
        double_plant(view, random, x + ox, y, z + oz, plant);
    }
}

fn decorate_biome(view: &mut View, random: &mut JavaRandom, biome: &'static LegacyBiome, x: i32, z: i32) {
    let mut counts = biome.decorator;

    match biome.style {
        Style::Plains { sunflowers } => {
            let noise = fixed().grass_color.sample((x + 8) as f64 / 200.0, (z + 8) as f64 / 200.0);

            if noise < -0.8 {
                counts.flowers = 15;
                counts.grass = 5;
            } else {
                counts.flowers = 4;
                counts.grass = 10;
                double_plants(view, random, x, z, 7, DOUBLE_GRASS);
            }

            if sunflowers {
                double_plants(view, random, x, z, 10, SUNFLOWER);
            }
        }
        Style::Forest { .. } | Style::RoofedMutated => {
            let kind = if let Style::Forest { kind } = biome.style { kind } else { 3 };

            if kind == 3 {
                for i in 0..4 {
                    for j in 0..4 {
                        let bx = x + i * 4 + 1 + 8 + random.next_int_bounded(3);
                        let bz = z + j * 4 + 1 + 8 + random.next_int_bounded(3);
                        let by = view.height(bx, bz);

                        if random.next_int_bounded(20) == 0 {
                            trees::huge_mushroom(view, random, bx, by, bz);
                        } else {
                            let tree = pick_tree(biome, random);

                            if trees::grow(view, random, tree, bx, by, bz) {
                                trees::after(view, random, tree, bx, by, bz);
                            }
                        }
                    }
                }
            }

            let mut tries = random.next_int_bounded(5) - 3;

            if kind == 1 {
                tries += 2;
            }

            for _ in 0..tries {
                let plant = [LILAC, ROSE_BUSH, PEONY][random.next_int_bounded(3) as usize];

                for _ in 0..5 {
                    let (ox, oz) = spot(random);
                    let y = random.next_int_bounded(view.height(x + ox, z + oz) + 32);

                    if double_plant(view, random, x + ox, y, z + oz, plant) {
                        break;
                    }
                }
            }
        }
        Style::Taiga { kind } => {
            if kind == 1 || kind == 2 {
                let count = random.next_int_bounded(3);

                for _ in 0..count {
                    let (ox, oz) = spot(random);
                    let y = view.height(x + ox, z + oz);
                    boulder(view, random, x + ox, y, z + oz);
                }
            }

            double_plants(view, random, x, z, 7, LARGE_FERN);
        }
        Style::Savanna => double_plants(view, random, x, z, 7, DOUBLE_GRASS),
        Style::Snow { spikes: true } => {
            for _ in 0..3 {
                let (ox, oz) = spot(random);
                let y = view.height(x + ox, z + oz);
                ice_spike(view, random, x + ox, y, z + oz);
            }

            for _ in 0..2 {
                let (ox, oz) = spot(random);
                let y = view.height(x + ox, z + oz);
                ice_path(view, random, x + ox, y, z + oz);
            }
        }
        _ => {}
    }

    decorator(view, random, biome, &counts, x, z);

    match biome.style {
        Style::Hills => {
            let count = 3 + random.next_int_bounded(6);

            for _ in 0..count {
                let bx = x + random.next_int_bounded(16);
                let by = random.next_int_bounded(28) + 4;
                let bz = z + random.next_int_bounded(16);

                if is_stone(view.get(bx, by, bz)) {
                    view.set(bx, by, bz, blocks::EMERALD_ORE);
                }
            }

            for _ in 0..7 {
                let bx = x + random.next_int_bounded(16);
                let by = random.next_int_bounded(64);
                let bz = z + random.next_int_bounded(16);
                ore(view, random, bx, by, bz, blocks::INFESTED_STONE, 9);
            }
        }
        Style::Jungle { .. } => {
            let (ox, oz) = spot(random);
            let bound = view.height(x + ox, z + oz) * 2;
            let y = random.next_int_bounded(bound);
            melons(view, random, x + ox, y, z + oz);

            for _ in 0..50 {
                let (ox, oz) = spot(random);
                hanging_vines(view, random, x + ox, 64, z + oz);
            }
        }
        //desert wells come here in the game, with the structures
        _ => {}
    }
}

/// Up to twice the ground height, as the game picks heights for plants.
fn twice_height(view: &View, random: &mut JavaRandom, x: i32, z: i32) -> Option<i32> {
    let bound = view.height(x, z) * 2;
    (bound > 0).then(|| random.next_int_bounded(bound))
}

fn decorator(view: &mut View, random: &mut JavaRandom, biome: &'static LegacyBiome, counts: &super::biomes::Decorator, x: i32, z: i32) {
    ores(view, random, x, z, biome);

    for _ in 0..counts.sand {
        let (ox, oz) = spot(random);
        let y = view.top_solid_or_liquid(x + ox, z + oz);
        disc(view, random, x + ox, y, z + oz, blocks::SAND, 7, 2);
    }

    for _ in 0..counts.clay {
        let (ox, oz) = spot(random);
        let y = view.top_solid_or_liquid(x + ox, z + oz);
        disc(view, random, x + ox, y, z + oz, blocks::CLAY, 4, 1);
    }

    for _ in 0..counts.gravel {
        let (ox, oz) = spot(random);
        let y = view.top_solid_or_liquid(x + ox, z + oz);
        disc(view, random, x + ox, y, z + oz, blocks::GRAVEL, 6, 2);
    }

    let mut tree_count = counts.trees;

    if random.next_int_bounded(10) == 0 {
        tree_count += 1;
    }

    for _ in 0..tree_count {
        let (ox, oz) = spot(random);
        let tree = pick_tree(biome, random);
        tree_at(view, random, tree, x + ox, z + oz);
    }

    for _ in 0..counts.big_mushrooms {
        let (ox, oz) = spot(random);
        let y = view.height(x + ox, z + oz);
        trees::huge_mushroom(view, random, x + ox, y, z + oz);
    }

    for _ in 0..counts.flowers {
        let (ox, oz) = spot(random);
        let bound = view.height(x + ox, z + oz) + 32;

        if bound > 0 {
            let y = random.next_int_bounded(bound);
            let flower = pick_flower(biome, random, x + ox, z + oz);
            flowers(view, random, x + ox, y, z + oz, flower);
        }
    }

    for _ in 0..counts.grass {
        let (ox, oz) = spot(random);

        if let Some(y) = twice_height(view, random, x + ox, z + oz) {
            let plant = grass_for(biome, random);
            tall_grass(view, random, x + ox, y, z + oz, plant);
        }
    }

    for _ in 0..counts.dead_bushes {
        let (ox, oz) = spot(random);

        if let Some(y) = twice_height(view, random, x + ox, z + oz) {
            dead_bushes(view, random, x + ox, y, z + oz);
        }
    }

    for _ in 0..counts.waterlilies {
        let (ox, oz) = spot(random);

        if let Some(mut y) = twice_height(view, random, x + ox, z + oz) {
            while y > 0 && view.is_air(x + ox, y - 1, z + oz) {
                y -= 1;
            }

            lily_pads(view, random, x + ox, y, z + oz);
        }
    }

    for _ in 0..counts.mushrooms {
        if random.next_int_bounded(4) == 0 {
            let (ox, oz) = spot(random);
            let y = view.height(x + ox, z + oz);
            mushrooms(view, random, x + ox, y, z + oz, blocks::BROWN_MUSHROOM);
        }

        if random.next_int_bounded(8) == 0 {
            let (ox, oz) = spot(random);

            if let Some(y) = twice_height(view, random, x + ox, z + oz) {
                mushrooms(view, random, x + ox, y, z + oz, blocks::RED_MUSHROOM);
            }
        }
    }

    if random.next_int_bounded(4) == 0 {
        let (ox, oz) = spot(random);

        if let Some(y) = twice_height(view, random, x + ox, z + oz) {
            mushrooms(view, random, x + ox, y, z + oz, blocks::BROWN_MUSHROOM);
        }
    }

    if random.next_int_bounded(8) == 0 {
        let (ox, oz) = spot(random);

        if let Some(y) = twice_height(view, random, x + ox, z + oz) {
            mushrooms(view, random, x + ox, y, z + oz, blocks::RED_MUSHROOM);
        }
    }

    for _ in 0..counts.reeds + 10 {
        let (ox, oz) = spot(random);

        if let Some(y) = twice_height(view, random, x + ox, z + oz) {
            reeds(view, random, x + ox, y, z + oz);
        }
    }

    if random.next_int_bounded(32) == 0 {
        let (ox, oz) = spot(random);

        if let Some(y) = twice_height(view, random, x + ox, z + oz) {
            pumpkins(view, random, x + ox, y, z + oz);
        }
    }

    for _ in 0..counts.cacti {
        let (ox, oz) = spot(random);

        if let Some(y) = twice_height(view, random, x + ox, z + oz) {
            cacti(view, random, x + ox, y, z + oz);
        }
    }

    for _ in 0..50 {
        let ox = random.next_int_bounded(16) + 8;
        let y = {
            let bound = random.next_int_bounded(248) + 8;
            random.next_int_bounded(bound)
        };
        let oz = random.next_int_bounded(16) + 8;
        spring(view, x + ox, y, z + oz, blocks::WATER);
    }

    for _ in 0..20 {
        let ox = random.next_int_bounded(16) + 8;
        let y = {
            let a = random.next_int_bounded(240) + 8;
            let b = random.next_int_bounded(a) + 8;
            random.next_int_bounded(b)
        };
        let oz = random.next_int_bounded(16) + 8;
        spring(view, x + ox, y, z + oz, blocks::LAVA);
    }
}
