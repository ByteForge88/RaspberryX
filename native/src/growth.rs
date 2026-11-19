//! Random ticks, and the trees saplings grow into.
//!
//! Every tick the game picks a few blocks at random in each sub-chunk near a player. Most of
//! them do nothing when picked, so the picking happens here and PHP only hears about blocks
//! that grow, spread, melt or decay.

use std::collections::{HashMap, HashSet};
use std::f64::consts::PI as PI64;
use std::f32::consts::PI;
use std::sync::{Arc, RwLock};

use crate::biome::Tree;
use crate::chunk::{Chunk, MAX_Y, MIN_SUBCHUNK, MIN_Y};
use crate::generator::blocks::{self, Block};
use crate::generator::features::{self, Canvas};
use crate::generator::pre_normal::{cos, floor, sin};
use crate::random::JavaRandom;

static TICKING: RwLock<Option<Arc<HashSet<u32>>>> = RwLock::new(None);

pub fn set_ticking(states: impl Iterator<Item = u32>) {
    let mut guard = TICKING.write().unwrap_or_else(|poisoned| poisoned.into_inner());
    *guard = Some(Arc::new(states.collect()));
}

pub fn ticking() -> Arc<HashSet<u32>> {
    let guard = TICKING.read().unwrap_or_else(|poisoned| poisoned.into_inner());
    guard.clone().unwrap_or_default()
}

pub struct Picker(u64);

impl Picker {
    pub fn new(seed: u64) -> Self {
        Picker(seed)
    }

    #[inline]
    fn next(&mut self) -> u64 {
        self.0 = self.0.wrapping_add(0x9E3779B97F4A7C15);
        let mut z = self.0;
        z = (z ^ (z >> 30)).wrapping_mul(0xBF58476D1CE4E5B9);
        z = (z ^ (z >> 27)).wrapping_mul(0x94D049BB133111EB);
        z ^ (z >> 31)
    }
}

pub fn pick(chunk: &Chunk, count: u32, picker: &mut Picker, ticking: &HashSet<u32>, out: &mut Vec<(u8, i32, u8, u32)>) {
    for (sub, subchunk) in chunk.subchunks.iter().enumerate() {
        let Some(storage) = subchunk.layers.first() else { continue };

        if !storage.palette.iter().any(|state| ticking.contains(state)) {
            continue;
        }

        let bottom = (sub as i32 + MIN_SUBCHUNK) << 4;

        for _ in 0..count {
            let index = (picker.next() & 0xfff) as usize;
            let state = storage.get(index);

            if ticking.contains(&state) {
                out.push(((index >> 8) as u8, bottom + (index & 15) as i32, ((index >> 4) & 15) as u8, state));
            }
        }
    }
}

pub struct WorldCanvas<'a> {
    chunks: HashMap<(i32, i32), &'a Chunk>,
    blocks: &'a HashMap<u32, Block>,
    states: &'a [u32],
    soil: &'a HashSet<u32>,
    cleared: Vec<(i32, i32, i32)>,
    writes: HashMap<(i32, i32, i32), Block>,
    order: Vec<(i32, i32, i32)>,
}

const HEIGHT: i32 = MAX_Y - MIN_Y + 1;

impl<'a> WorldCanvas<'a> {
    pub fn new(chunks: HashMap<(i32, i32), &'a Chunk>, blocks: &'a HashMap<u32, Block>, states: &'a [u32], soil: &'a HashSet<u32>, cleared: Vec<(i32, i32, i32)>) -> Self {
        WorldCanvas { chunks, blocks, states, soil, cleared, writes: HashMap::new(), order: Vec::new() }
    }

    fn state_at(&self, x: i32, y: i32, z: i32) -> Option<u32> {
        if !(MIN_Y..=MAX_Y).contains(&y) {
            return None;
        }

        let chunk = self.chunks.get(&(x >> 4, z >> 4))?;

        Some(chunk.get_block((x & 15) as usize, y, (z & 15) as usize, 0))
    }

    pub fn changes(&self) -> Vec<(i32, i32, i32, u32)> {
        self.order.iter().map(|&(x, y, z)| (x, y, z, self.states[self.writes[&(x, y, z)] as usize])).collect()
    }
}

impl Canvas for WorldCanvas<'_> {
    fn get(&self, x: i32, y: i32, z: i32) -> Block {
        let world_y = y + MIN_Y;

        if let Some(block) = self.writes.get(&(x, world_y, z)) {
            return *block;
        }

        if self.cleared.contains(&(x, world_y, z)) {
            return blocks::AIR;
        }

        match self.state_at(x, world_y, z) {
            None if world_y > MAX_Y => blocks::AIR,
            None => blocks::BARRIER,
            Some(state) => match self.blocks.get(&state) {
                Some(block) => *block,
                None if self.soil.contains(&state) => blocks::DIRT,
                None => blocks::BARRIER,
            },
        }
    }

    fn set(&mut self, x: i32, y: i32, z: i32, block: Block) {
        let world_y = y + MIN_Y;
        let Some(state) = self.state_at(x, world_y, z) else { return };

        if block == blocks::DIRT && self.soil.contains(&state) {
            return;
        }

        if self.writes.insert((x, world_y, z), block).is_none() {
            self.order.push((x, world_y, z));
        }
    }

    fn height(&self) -> i32 {
        HEIGHT
    }
}

pub const OAK: i32 = 0;
pub const BIG_OAK: i32 = 1;
pub const BIRCH: i32 = 2;
pub const SPRUCE: i32 = 3;
pub const MEGA_SPRUCE: i32 = 4;
pub const MEGA_PINE: i32 = 5;
pub const JUNGLE: i32 = 6;
pub const MEGA_JUNGLE: i32 = 7;
pub const ACACIA: i32 = 8;
pub const DARK_OAK: i32 = 9;
pub const CHERRY: i32 = 10;
pub const MANGROVE: i32 = 11;
pub const PALE_OAK: i32 = 12;
pub const AZALEA: i32 = 13;
pub const CRIMSON_FUNGUS: i32 = 14;
pub const WARPED_FUNGUS: i32 = 15;

pub fn grow_tree(canvas: &mut WorldCanvas, kind: i32, x: i32, y: i32, z: i32, seed: i64) -> bool {
    let mut random = JavaRandom::new(seed);
    let y = y - MIN_Y;

    match kind {
        OAK => features::tree(canvas, &mut random, Tree::Oak, x, y, z),
        BIG_OAK => big_oak(canvas, &mut random, x, y, z),
        BIRCH => features::tree(canvas, &mut random, Tree::Birch, x, y, z),
        SPRUCE => features::tree(canvas, &mut random, Tree::Spruce, x, y, z),
        MEGA_SPRUCE => mega_pine(canvas, &mut random, x, y, z, true),
        MEGA_PINE => mega_pine(canvas, &mut random, x, y, z, false),
        JUNGLE => features::tree(canvas, &mut random, Tree::Jungle, x, y, z),
        MEGA_JUNGLE => mega_jungle(canvas, &mut random, x, y, z),
        ACACIA => features::tree(canvas, &mut random, Tree::Acacia, x, y, z),
        DARK_OAK => features::tree(canvas, &mut random, Tree::DarkOak, x, y, z),
        CHERRY => features::tree(canvas, &mut random, Tree::Cherry, x, y, z),
        MANGROVE => features::tree(canvas, &mut random, Tree::Mangrove, x, y, z),
        PALE_OAK => pale_oak(canvas, &mut random, x, y, z),
        AZALEA => azalea(canvas, &mut random, x, y, z),
        CRIMSON_FUNGUS => huge_fungus(canvas, &mut random, x, y, z, true),
        WARPED_FUNGUS => huge_fungus(canvas, &mut random, x, y, z, false),
        _ => false,
    }
}

fn is_ground(block: Block) -> bool {
    matches!(block, blocks::GRASS_BLOCK | blocks::DIRT | blocks::COARSE_DIRT | blocks::PODZOL | blocks::MYCELIUM)
}

fn can_grow_into(block: Block) -> bool {
    block == blocks::AIR || blocks::is_leaves(block) || is_ground(block) || blocks::is_log(block) || blocks::is_vine(block) || blocks::is_plant(block)
}

fn air_or_leaves(block: Block) -> bool {
    block == blocks::AIR || blocks::is_leaves(block)
}

fn dirt_under(canvas: &mut impl Canvas, x: i32, y: i32, z: i32) {
    if !matches!(canvas.get(x, y, z), blocks::DIRT | blocks::COARSE_DIRT | blocks::PODZOL) {
        canvas.set(x, y, z, blocks::DIRT);
    }
}

fn huge_height(random: &mut JavaRandom, base: i32, extra: i32) -> i32 {
    let mut height = random.next_int_bounded(3) + base;

    if extra > 1 {
        height += random.next_int_bounded(extra);
    }

    height
}

fn huge_has_room(canvas: &mut impl Canvas, x: i32, y: i32, z: i32, height: i32) -> bool {
    if y < 2 || y + height + 1 >= canvas.height() {
        return false;
    }

    for i in 0..=1 + height {
        let radius = if i == 0 { 1 } else { 2 };

        for dx in -radius..=radius {
            for dz in -radius..=radius {
                if !can_grow_into(canvas.get(x + dx, y + i, z + dz)) {
                    return false;
                }
            }
        }
    }

    for (dx, dz) in [(0, 0), (1, 0), (0, 1), (1, 1)] {
        if !is_ground(canvas.get(x + dx, y - 1, z + dz)) {
            return false;
        }
    }

    for (dx, dz) in [(0, 0), (1, 0), (0, 1), (1, 1)] {
        dirt_under(canvas, x + dx, y - 1, z + dz);
    }

    true
}

fn wide_layer(canvas: &mut impl Canvas, x: i32, y: i32, z: i32, radius: i32, leaves: Block) {
    let square = radius * radius;

    for j in -radius..=radius + 1 {
        for k in -radius..=radius + 1 {
            let (l, m) = (j - 1, k - 1);

            if (j * j + k * k <= square || l * l + m * m <= square || j * j + m * m <= square || l * l + k * k <= square) && air_or_leaves(canvas.get(x + j, y, z + k)) {
                canvas.set(x + j, y, z + k, leaves);
            }
        }
    }
}

fn round_layer(canvas: &mut impl Canvas, x: i32, y: i32, z: i32, radius: i32, leaves: Block) {
    let square = radius * radius;

    for j in -radius..=radius {
        for k in -radius..=radius {
            if j * j + k * k <= square && air_or_leaves(canvas.get(x + j, y, z + k)) {
                canvas.set(x + j, y, z + k, leaves);
            }
        }
    }
}

fn mega_pine(canvas: &mut impl Canvas, random: &mut JavaRandom, x: i32, y: i32, z: i32, spruce: bool) -> bool {
    let height = huge_height(random, 13, 15);

    if !huge_has_room(canvas, x, y, z, height) {
        return false;
    }

    let top = y + height;
    let crown = random.next_int_bounded(5) + if spruce { 13 } else { 3 };
    let mut last = 0;

    for by in top - crown..=top {
        let depth = top - by;
        let radius = (depth as f32 / crown as f32 * 3.5).floor() as i32;
        let extra = (depth > 0 && radius == last && (by & 1) == 0) as i32;
        wide_layer(canvas, x, by, z, radius + extra, blocks::SPRUCE_LEAVES);
        last = radius;
    }

    for i in 0..height {
        for (dx, dz) in [(0, 0), (1, 0), (1, 1), (0, 1)] {
            if (dx, dz) != (0, 0) && i >= height - 1 {
                continue;
            }

            if air_or_leaves(canvas.get(x + dx, y + i, z + dz)) {
                canvas.set(x + dx, y + i, z + dz, blocks::SPRUCE_LOG);
            }
        }
    }

    for (dx, dz) in [(-1, -1), (2, -1), (-1, 2), (2, 2)] {
        podzol_patch(canvas, x + dx, y, z + dz);
    }

    for _ in 0..5 {
        let j = random.next_int_bounded(64);
        let (k, l) = (j % 8, j / 8);

        if k == 0 || k == 7 || l == 0 || l == 7 {
            podzol_patch(canvas, x - 3 + k, y, z - 3 + l);
        }
    }

    true
}

fn podzol_patch(canvas: &mut impl Canvas, x: i32, y: i32, z: i32) {
    for i in -2..=2 {
        for j in -2..=2 {
            if i32::abs(i) == 2 && i32::abs(j) == 2 {
                continue;
            }

            for dy in (-3..=2).rev() {
                let block = canvas.get(x + i, y + dy, z + j);

                if matches!(block, blocks::GRASS_BLOCK | blocks::DIRT | blocks::COARSE_DIRT) {
                    canvas.set(x + i, y + dy, z + j, blocks::PODZOL);
                    break;
                }

                if block != blocks::AIR && dy < 0 {
                    break;
                }
            }
        }
    }
}

fn maybe_vine(canvas: &mut impl Canvas, random: &mut JavaRandom, x: i32, y: i32, z: i32, block: Block) {
    if random.next_int_bounded(3) > 0 && canvas.get(x, y, z) == blocks::AIR {
        canvas.set(x, y, z, block);
    }
}

fn mega_jungle(canvas: &mut impl Canvas, random: &mut JavaRandom, x: i32, y: i32, z: i32) -> bool {
    let height = huge_height(random, 10, 20);

    if !huge_has_room(canvas, x, y, z, height) {
        return false;
    }

    for dy in -2..=0 {
        wide_layer(canvas, x, y + height + dy, z, 2 + 1 - dy, blocks::JUNGLE_LEAVES);
    }

    let mut by = y + height - 2 - random.next_int_bounded(4);

    while by > y + height / 2 {
        let angle = random.next_float() * PI * 2.0;
        let (mut bx, mut bz) = (x, z);

        for i in 0..5 {
            bx = x + (1.5 + cos(angle) * i as f32) as i32;
            bz = z + (1.5 + sin(angle) * i as f32) as i32;
            canvas.set(bx, by - 3 + i / 2, bz, blocks::JUNGLE_LOG);
        }

        let spread = 1 + random.next_int_bounded(2);

        for ly in by - spread..=by {
            round_layer(canvas, bx, ly, bz, 1 - (ly - by), blocks::JUNGLE_LEAVES);
        }

        by -= 2 + random.next_int_bounded(4);
    }

    let sides = [
        (0, 0, [(-1, 0, blocks::VINE_EAST), (0, -1, blocks::VINE_SOUTH)]),
        (1, 0, [(2, 0, blocks::VINE_WEST), (1, -1, blocks::VINE_SOUTH)]),
        (1, 1, [(2, 1, blocks::VINE_WEST), (1, 2, blocks::VINE_NORTH)]),
        (0, 1, [(-1, 1, blocks::VINE_EAST), (0, 2, blocks::VINE_NORTH)]),
    ];

    for i in 0..height {
        let by = y + i;

        for (n, (dx, dz, vines)) in sides.iter().enumerate() {
            if n > 0 && i >= height - 1 {
                continue;
            }

            if can_grow_into(canvas.get(x + dx, by, z + dz)) {
                canvas.set(x + dx, by, z + dz, blocks::JUNGLE_LOG);

                if i > 0 {
                    for (vx, vz, vine) in vines {
                        maybe_vine(canvas, random, x + vx, by, z + vz, *vine);
                    }
                }
            }
        }
    }

    true
}

/// The branchy big oak, with a random of its own seeded from the one it is given.
fn big_oak(canvas: &mut impl Canvas, random: &mut JavaRandom, x: i32, y: i32, z: i32) -> bool {
    let mut random = JavaRandom::new(random.next_long());
    let leaf_distance = 5;
    let mut height_limit = 5 + random.next_int_bounded(12);

    if !is_ground(canvas.get(x, y - 1, z)) || y + height_limit + leaf_distance >= canvas.height() {
        return false;
    }

    match block_line(canvas, (x, y, z), (x, y + height_limit - 1, z)) {
        None => {}
        Some(blocked) if blocked < 6 => return false,
        Some(blocked) => height_limit = blocked,
    }

    dirt_under(canvas, x, y - 1, z);

    let height = ((height_limit as f64 * 0.618) as i32).min(height_limit - 1);
    let per_layer = ((1.382 + (height_limit as f64 / 13.0).powi(2)) as i32).max(1);
    let trunk_top = y + height;
    let mut k = height_limit - leaf_distance;
    let mut nodes: Vec<((i32, i32, i32), i32)> = vec![((x, y + k, z), trunk_top)];

    let layer_size = |layer: i32| -> f32 {
        if (layer as f32) < height_limit as f32 * 0.3 {
            return -1.0;
        }

        let half = height_limit as f32 / 2.0;
        let from = half - layer as f32;

        if from == 0.0 {
            return half * 0.5;
        }

        if from.abs() >= half {
            return 0.0;
        }

        ((half * half - from * from) as f64).sqrt() as f32 * 0.5
    };

    while k >= 0 {
        let size = layer_size(k);

        if size >= 0.0 {
            for _ in 0..per_layer {
                let distance = size as f64 * (random.next_float() as f64 + 0.328);
                let angle = (random.next_float() * 2.0) as f64 * PI64;
                let nx = floor(x as f64 + distance * angle.sin() + 0.5);
                let nz = floor(z as f64 + distance * angle.cos() + 0.5);
                let node = (nx, y + k - 1, nz);

                if block_line(canvas, node, (node.0, node.1 + leaf_distance, node.2)).is_none() {
                    let (dx, dz) = (x - node.0, z - node.2);
                    let base = node.1 as f64 - ((dx * dx + dz * dz) as f64).sqrt() * 0.381;
                    let base = if base > trunk_top as f64 { trunk_top } else { base as i32 };

                    if block_line(canvas, (x, base, z), node).is_none() {
                        nodes.push((node, base));
                    }
                }
            }
        }

        k -= 1;
    }

    for &((nx, ny, nz), _) in &nodes {
        for i in 0..leaf_distance {
            let size = if i != 0 && i != leaf_distance - 1 { 3.0f32 } else { 2.0 };
            let reach = (size as f64 + 0.618) as i32;

            for dx in -reach..=reach {
                for dz in -reach..=reach {
                    let inside = (dx.abs() as f64 + 0.5).powi(2) + (dz.abs() as f64 + 0.5).powi(2) <= (size * size) as f64;

                    if inside && air_or_leaves(canvas.get(nx + dx, ny + i, nz + dz)) {
                        canvas.set(nx + dx, ny + i, nz + dz, blocks::OAK_LEAVES);
                    }
                }
            }
        }
    }

    limb(canvas, (x, y, z), (x, y + height, z));

    for &(node, base) in &nodes {
        if (x, base, z) != node && (base - y) as f64 >= height_limit as f64 * 0.2 {
            limb(canvas, (x, base, z), node);
        }
    }

    true
}

fn line_steps(from: (i32, i32, i32), to: (i32, i32, i32)) -> (i32, f32, f32, f32) {
    let (dx, dy, dz) = (to.0 - from.0, to.1 - from.1, to.2 - from.2);
    let steps = dx.abs().max(dy.abs()).max(dz.abs());

    (steps, dx as f32 / steps as f32, dy as f32 / steps as f32, dz as f32 / steps as f32)
}

fn line_at(from: (i32, i32, i32), j: i32, fx: f32, fy: f32, fz: f32) -> (i32, i32, i32) {
    (from.0 + floor((0.5 + j as f32 * fx) as f64), from.1 + floor((0.5 + j as f32 * fy) as f64), from.2 + floor((0.5 + j as f32 * fz) as f64))
}

/// How far along the line it is free to grow, None for all the way.
fn block_line(canvas: &impl Canvas, from: (i32, i32, i32), to: (i32, i32, i32)) -> Option<i32> {
    let (steps, fx, fy, fz) = line_steps(from, to);

    if steps == 0 {
        return None;
    }

    for j in 0..=steps {
        let (bx, by, bz) = line_at(from, j, fx, fy, fz);

        if !can_grow_into(canvas.get(bx, by, bz)) {
            return Some(j);
        }
    }

    None
}

fn limb(canvas: &mut impl Canvas, from: (i32, i32, i32), to: (i32, i32, i32)) {
    let (steps, fx, fy, fz) = line_steps(from, to);

    for j in 0..=steps.max(0) {
        let (bx, by, bz) = if steps == 0 { from } else { line_at(from, j, fx, fy, fz) };
        canvas.set(bx, by, bz, blocks::OAK_LOG);
    }
}

fn leaves_at(canvas: &mut impl Canvas, x: i32, y: i32, z: i32, leaves: Block) -> bool {
    if blocks::is_replaceable(canvas.get(x, y, z)) {
        canvas.set(x, y, z, leaves);
        return true;
    }

    false
}

/// A pale oak: a dark oak's thick trunk, a pale crown and moss hanging from it.
fn pale_oak(canvas: &mut impl Canvas, random: &mut JavaRandom, x: i32, y: i32, z: i32) -> bool {
    let height = 6 + random.next_int_bounded(3);

    if y < 1 || y + height + 2 >= canvas.height() {
        return false;
    }

    for (ox, oz) in [(0, 0), (1, 0), (0, 1), (1, 1)] {
        if !is_ground(canvas.get(x + ox, y - 1, z + oz)) {
            return false;
        }
    }

    for by in y..=y + height + 1 {
        let reach = if by == y { 0 } else if by >= y + height - 2 { 2 } else { 1 };

        for bx in x - reach..=x + 1 + reach {
            for bz in z - reach..=z + 1 + reach {
                if !blocks::is_replaceable(canvas.get(bx, by, bz)) {
                    return false;
                }
            }
        }
    }

    for (ox, oz) in [(0, 0), (1, 0), (0, 1), (1, 1)] {
        dirt_under(canvas, x + ox, y - 1, z + oz);

        for by in y..y + height {
            canvas.set(x + ox, by, z + oz, blocks::PALE_OAK_LOG);
        }
    }

    let mut hanging = Vec::new();

    for (layer, reach) in [(-2, 4), (-1, 4), (0, 3), (1, 2)] {
        let by = y + height + layer;

        for bx in x - reach..=x + 1 + reach {
            for bz in z - reach..=z + 1 + reach {
                let (dx, dz) = (if bx <= x { x - bx } else { bx - x - 1 }, if bz <= z { z - bz } else { bz - z - 1 });

                if dx == reach && dz == reach && (layer >= 0 || random.next_int_bounded(2) == 0) {
                    continue;
                }

                if leaves_at(canvas, bx, by, bz, blocks::PALE_OAK_LEAVES) && layer == -2 && random.next_int_bounded(4) == 0 {
                    hanging.push((bx, by - 1, bz));
                }
            }
        }
    }

    for (bx, by, bz) in hanging {
        let length = 1 + random.next_int_bounded(3);

        for step in 0..length {
            if canvas.get(bx, by - step, bz) != blocks::AIR {
                break;
            }

            let tip = step == length - 1 || canvas.get(bx, by - step - 1, bz) != blocks::AIR;
            canvas.set(bx, by - step, bz, if tip { blocks::PALE_HANGING_MOSS_TIP } else { blocks::PALE_HANGING_MOSS });

            if tip {
                break;
            }
        }
    }

    true
}

fn azalea(canvas: &mut impl Canvas, random: &mut JavaRandom, x: i32, y: i32, z: i32) -> bool {
    let height = 4 + random.next_int_bounded(3) + random.next_int_bounded(1);

    if y < 1 || y + height + 1 >= canvas.height() || !is_ground(canvas.get(x, y - 1, z)) {
        return false;
    }

    for dy in 0..=height + 1 {
        let radius = if dy < 1 { 0 } else { 1 };

        for dx in -radius..=radius {
            for dz in -radius..=radius {
                let block = canvas.get(x + dx, y + dy, z + dz);

                if !blocks::is_replaceable(block) && !blocks::is_log(block) {
                    return false;
                }
            }
        }
    }

    let (sx, sz) = [(0, -1), (0, 1), (-1, 0), (1, 0)][random.next_int_bounded(4) as usize];
    let top = height - 1;
    let (mut bx, mut by, mut bz) = (x, y, z);
    let mut attachments = Vec::new();

    canvas.set(x, y - 1, z, blocks::ROOTED_DIRT);

    for j in 0..=top {
        if j + 1 >= top + random.next_int_bounded(2) {
            bx += sx;
            bz += sz;
        }

        if blocks::is_replaceable(canvas.get(bx, by, bz)) {
            canvas.set(bx, by, bz, blocks::OAK_LOG);
        }

        if j >= 3 {
            attachments.push((bx, by, bz));
        }

        by += 1;
    }

    let bend = 1 + random.next_int_bounded(2);

    for _ in 0..=bend {
        if blocks::is_replaceable(canvas.get(bx, by, bz)) {
            canvas.set(bx, by, bz, blocks::OAK_LOG);
        }

        attachments.push((bx, by, bz));
        bx += sx;
        bz += sz;
    }

    for (ax, ay, az) in attachments {
        for _ in 0..50 {
            let lx = ax + random.next_int_bounded(3) - random.next_int_bounded(3);
            let ly = ay + random.next_int_bounded(2) - random.next_int_bounded(2);
            let lz = az + random.next_int_bounded(3) - random.next_int_bounded(3);
            let leaves = if random.next_int_bounded(4) == 0 { blocks::AZALEA_LEAVES_FLOWERED } else { blocks::AZALEA_LEAVES };

            leaves_at(canvas, lx, ly, lz, leaves);
        }
    }

    true
}

fn between(random: &mut JavaRandom, min: i32, max: i32) -> i32 {
    min + random.next_int_bounded(max - min + 1)
}

fn huge_fungus(canvas: &mut impl Canvas, random: &mut JavaRandom, x: i32, y: i32, z: i32, crimson: bool) -> bool {
    let nylium = if crimson { blocks::CRIMSON_NYLIUM } else { blocks::WARPED_NYLIUM };

    if y < 1 || canvas.get(x, y - 1, z) != nylium {
        return false;
    }

    let mut height = between(random, 4, 13);

    if random.next_int_bounded(12) == 0 {
        height *= 2;
    }

    if y + height + 1 >= canvas.height() {
        return false;
    }

    let stem = if crimson { blocks::CRIMSON_STEM } else { blocks::WARPED_STEM };
    let hat = if crimson { blocks::NETHER_WART_BLOCK } else { blocks::WARPED_WART_BLOCK };

    for dy in 0..height {
        if blocks::is_replaceable(canvas.get(x, y + dy, z)) {
            canvas.set(x, y + dy, z, stem);
        }
    }

    let size = (random.next_int_bounded(1 + height / 3) + 5).min(height);
    let bottom = height - size;

    for k in bottom..=height {
        let mut radius = if k < height - random.next_int_bounded(3) { 2 } else { 1 };

        if size > 8 && k < bottom + 4 {
            radius = 3;
        }

        for m in -radius..=radius {
            for n in -radius..=radius {
                let edge_x = m == -radius || m == radius;
                let edge_z = n == -radius || n == radius;
                let inside = !edge_x && !edge_z && k != height;
                let corner = edge_x && edge_z;
                let (hx, hy, hz) = (x + m, y + k, z + n);

                if !blocks::is_replaceable(canvas.get(hx, hy, hz)) {
                    continue;
                }

                if k < bottom + 3 {
                    if !inside {
                        hat_drop(canvas, random, hx, hy, hz, hat, crimson);
                    }
                } else if inside {
                    hat_block(canvas, random, hx, hy, hz, hat, 0.1, 0.2, if crimson { 0.1 } else { 0.0 });
                } else if corner {
                    hat_block(canvas, random, hx, hy, hz, hat, 0.01, 0.7, if crimson { 0.083 } else { 0.0 });
                } else {
                    hat_block(canvas, random, hx, hy, hz, hat, 0.0005, 0.98, if crimson { 0.07 } else { 0.0 });
                }
            }
        }
    }

    true
}

#[allow(clippy::too_many_arguments)]
fn hat_block(canvas: &mut impl Canvas, random: &mut JavaRandom, x: i32, y: i32, z: i32, hat: Block, decor: f32, chance: f32, vines: f32) {
    if random.next_float() < decor {
        canvas.set(x, y, z, blocks::SHROOMLIGHT);
    } else if random.next_float() < chance {
        canvas.set(x, y, z, hat);

        if random.next_float() < vines {
            weeping_vines(canvas, random, x, y - 1, z);
        }
    }
}

fn hat_drop(canvas: &mut impl Canvas, random: &mut JavaRandom, x: i32, y: i32, z: i32, hat: Block, crimson: bool) {
    if canvas.get(x, y - 1, z) == hat {
        canvas.set(x, y, z, hat);
    } else if random.next_float() < 0.15 {
        canvas.set(x, y, z, hat);

        if crimson && random.next_int_bounded(11) == 0 {
            weeping_vines(canvas, random, x, y - 1, z);
        }
    }
}

fn weeping_vines(canvas: &mut impl Canvas, random: &mut JavaRandom, x: i32, y: i32, z: i32) {
    if canvas.get(x, y, z) != blocks::AIR {
        return;
    }

    let mut length = between(random, 1, 5);

    if random.next_int_bounded(7) == 0 {
        length *= 2;
    }

    for i in 0..=length {
        let vy = y - i;

        if vy < 0 {
            break;
        }

        if canvas.get(x, vy, z) == blocks::AIR {
            if i == length || canvas.get(x, vy - 1, z) != blocks::AIR {
                canvas.set(x, vy, z, blocks::WEEPING_VINES_23 + between(random, 0, 2) as Block);
                break;
            }

            canvas.set(x, vy, z, blocks::WEEPING_VINES_25);
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn setup() -> (Vec<u32>, HashMap<u32, Block>) {
        let states: Vec<u32> = (0..blocks::count() as u32).map(|i| 1000 + i).collect();
        let lookup = states.iter().enumerate().map(|(i, s)| (*s, i as Block)).collect();
        (states, lookup)
    }

    fn ground(states: &[u32]) -> HashMap<(i32, i32), Chunk> {
        let mut chunks = HashMap::new();

        for cx in -2..=2 {
            for cz in -2..=2 {
                let mut chunk = Chunk::new(states[blocks::AIR as usize], 1);
                chunk.fill(MIN_Y, 62, 0, states[blocks::STONE as usize]);
                chunk.fill(63, 63, 0, states[blocks::GRASS_BLOCK as usize]);
                chunks.insert((cx, cz), chunk);
            }
        }

        chunks
    }

    #[test]
    fn every_kind_grows_on_open_grass() {
        let (states, lookup) = setup();
        let chunks = ground(&states);
        let soil = HashSet::new();

        for kind in OAK..=PALE_OAK {
            let mut grown = false;

            for seed in 0..20 {
                let refs = chunks.iter().map(|(k, v)| (*k, v)).collect();
                let mut canvas = WorldCanvas::new(refs, &lookup, &states, &soil, vec![]);

                if grow_tree(&mut canvas, kind, 0, 64, 0, seed) {
                    let changes = canvas.changes();
                    assert!(changes.len() > 10, "kind {kind} grew {} blocks", changes.len());
                    assert!(changes.iter().any(|&(x, y, z, _)| (x, y, z) == (0, 64, 0)), "kind {kind} has a trunk on the spot");
                    grown = true;
                    break;
                }
            }

            assert!(grown, "kind {kind} never fit");
        }
    }

    #[test]
    fn a_tree_does_not_grow_under_a_roof() {
        let (states, lookup) = setup();
        let mut chunks = ground(&states);
        chunks.get_mut(&(0, 0)).unwrap().fill(67, 67, 0, states[blocks::STONE as usize]);
        let soil = HashSet::new();
        let refs = chunks.iter().map(|(k, v)| (*k, v)).collect();
        let mut canvas = WorldCanvas::new(refs, &lookup, &states, &soil, vec![]);

        assert!(!grow_tree(&mut canvas, OAK, 5, 64, 5, 1));
    }

    #[test]
    fn picking_finds_only_what_ticks() {
        let (states, _) = setup();
        let chunks = ground(&states);
        let chunk = &chunks[&(0, 0)];
        let ticking: HashSet<u32> = [states[blocks::GRASS_BLOCK as usize]].into_iter().collect();
        let mut out = Vec::new();
        let mut picker = Picker::new(7);

        for _ in 0..1000 {
            pick(chunk, 3, &mut picker, &ticking, &mut out);
        }

        //the grass layer is 256 of the 4096 blocks of its sub-chunk
        assert!(out.len() > 100 && out.len() < 300, "{} picks", out.len());
        assert!(out.iter().all(|&(_, y, _, state)| y == 63 && state == states[blocks::GRASS_BLOCK as usize]));
    }
}