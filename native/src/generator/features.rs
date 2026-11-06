//! Things placed on top of terrain: ore veins, trees, plants. They draw on a Canvas, which
//! ignores writes outside of what it holds, so a tree at the edge is simply cut off.

use std::f64::consts::PI;

use super::blocks::{self, Block};
use crate::biome::Tree;
use crate::random::JavaRandom;

pub trait Canvas {
    fn get(&self, x: i32, y: i32, z: i32) -> Block;
    fn set(&mut self, x: i32, y: i32, z: i32, block: Block);
    /// one above the highest Y blocks can be at
    fn height(&self) -> i32;

    /// The Y above the highest block of a column that is not air, leaves or plants count too.
    fn top(&self, x: i32, z: i32) -> i32 {
        let mut y = self.height() - 1;

        while y > 0 && self.get(x, y, z) == blocks::AIR {
            y -= 1;
        }

        y + 1
    }

    /// The Y above the highest solid block, so through leaves and plants.
    fn ground(&self, x: i32, z: i32) -> i32 {
        let mut y = self.height() - 1;

        while y > 0 && !blocks::is_solid(self.get(x, y, z)) && self.get(x, y, z) != blocks::WATER && self.get(x, y, z) != blocks::ICE {
            y -= 1;
        }

        y + 1
    }
}

/// A vein of ore, a stretched blob through stone. `size` is about how many blocks it has.
pub fn ore(canvas: &mut impl Canvas, random: &mut JavaRandom, x: i32, y: i32, z: i32, ore: Block, size: i32) {
    let angle = random.next_float() as f64 * PI;
    let spread = size as f64 / 8.0;
    let (start_x, end_x) = (x as f64 + 8.0 + angle.sin() * spread, x as f64 + 8.0 - angle.sin() * spread);
    let (start_z, end_z) = (z as f64 + 8.0 + angle.cos() * spread, z as f64 + 8.0 - angle.cos() * spread);
    let start_y = (y + random.next_int_bounded(3) + 2) as f64;
    let end_y = (y + random.next_int_bounded(3) - 2) as f64;

    for step in 0..=size {
        let t = step as f64 / size as f64;
        let cx = start_x + (end_x - start_x) * t;
        let cy = start_y + (end_y - start_y) * t;
        let cz = start_z + (end_z - start_z) * t;
        let thickness = random.next_double() * size as f64 / 16.0;
        let width = ((step as f64 * PI / size as f64).sin() + 1.0) * thickness + 1.0;
        let radius = width / 2.0;

        for bx in (cx - radius).floor() as i32..=(cx + radius).floor() as i32 {
            let dx = (bx as f64 + 0.5 - cx) / radius;

            if dx * dx >= 1.0 {
                continue;
            }

            for by in (cy - radius).floor() as i32..=(cy + radius).floor() as i32 {
                let dy = (by as f64 + 0.5 - cy) / radius;

                if dx * dx + dy * dy >= 1.0 {
                    continue;
                }

                for bz in (cz - radius).floor() as i32..=(cz + radius).floor() as i32 {
                    let dz = (bz as f64 + 0.5 - cz) / radius;

                    if dx * dx + dy * dy + dz * dz < 1.0 && canvas.get(bx, by, bz) == blocks::STONE {
                        canvas.set(bx, by, bz, ore);
                    }
                }
            }
        }
    }
}

fn leaves_at(canvas: &mut impl Canvas, x: i32, y: i32, z: i32, leaves: Block) {
    if blocks::is_replaceable(canvas.get(x, y, z)) {
        canvas.set(x, y, z, leaves);
    }
}

fn log_at(canvas: &mut impl Canvas, x: i32, y: i32, z: i32, log: Block) {
    let block = canvas.get(x, y, z);

    if blocks::is_replaceable(block) || block == blocks::WATER {
        canvas.set(x, y, z, log);
    }
}

/// Whether a trunk this tall fits, standing on soil.
fn fits(canvas: &impl Canvas, x: i32, y: i32, z: i32, height: i32, radius: i32) -> bool {
    if y < 1 || y + height + 1 >= canvas.height() || !blocks::is_soil(canvas.get(x, y - 1, z)) {
        return false;
    }

    for by in y..=y + height + 1 {
        let reach = if by == y { 0 } else if by >= y + height - 1 { radius } else { 1.min(radius) };

        for bx in x - reach..=x + reach {
            for bz in z - reach..=z + reach {
                if !blocks::is_replaceable(canvas.get(bx, by, bz)) {
                    return false;
                }
            }
        }
    }

    true
}

fn plant_dirt(canvas: &mut impl Canvas, x: i32, y: i32, z: i32) {
    if canvas.get(x, y, z) != blocks::PODZOL {
        canvas.set(x, y, z, blocks::DIRT);
    }
}

/// The classic small tree: a trunk with a round crown of four layers.
fn round_tree(canvas: &mut impl Canvas, random: &mut JavaRandom, x: i32, y: i32, z: i32, log: Block, leaves: Block, height: i32, radius: i32, vines: bool) -> bool {
    if !fits(canvas, x, y, z, height, radius) {
        return false;
    }

    plant_dirt(canvas, x, y - 1, z);

    for by in y + height - 3..=y + height {
        let above_top = by - (y + height);
        let reach = radius - 1 - above_top / 2;

        for bx in x - reach..=x + reach {
            for bz in z - reach..=z + reach {
                let corner = (bx - x).abs() == reach && (bz - z).abs() == reach;

                //corners are left out at the top and now and then lower down
                if corner && (above_top == 0 || random.next_int_bounded(2) == 0) {
                    continue;
                }

                leaves_at(canvas, bx, by, bz, leaves);

                if vines && corner && random.next_int_bounded(4) == 0 {
                    hang_vine(canvas, random, bx, by, bz);
                }
            }
        }
    }

    for by in y..y + height {
        log_at(canvas, x, by, z, log);
    }

    true
}

/// A vine hanging down from a leaf block, on one of its free sides.
fn hang_vine(canvas: &mut impl Canvas, random: &mut JavaRandom, x: i32, y: i32, z: i32) {
    let (dx, dz, vine) = match random.next_int_bounded(4) {
        0 => (1, 0, blocks::VINE_WEST),
        1 => (-1, 0, blocks::VINE_EAST),
        2 => (0, 1, blocks::VINE_NORTH),
        _ => (0, -1, blocks::VINE_SOUTH),
    };

    let length = 1 + random.next_int_bounded(4);

    for step in 0..length {
        if canvas.get(x + dx, y - step, z + dz) != blocks::AIR {
            return;
        }

        canvas.set(x + dx, y - step, z + dz, vine);
    }
}

/// A spruce: a pointed crown of rings that grow and shrink on the way up.
fn spruce(canvas: &mut impl Canvas, random: &mut JavaRandom, x: i32, y: i32, z: i32) -> bool {
    let height = random.next_int_bounded(4) + 6;
    let bare = 1 + random.next_int_bounded(2);
    let crown = height - bare;
    let widest = 2 + random.next_int_bounded(2);

    if !fits(canvas, x, y, z, height, widest) {
        return false;
    }

    plant_dirt(canvas, x, y - 1, z);

    let mut radius = random.next_int_bounded(2);
    let mut limit = 1;
    let mut shrink = false;

    for step in 0..=crown {
        let by = y + height - step;

        for bx in x - radius..=x + radius {
            for bz in z - radius..=z + radius {
                if (bx - x).abs() != radius || (bz - z).abs() != radius || radius <= 0 {
                    leaves_at(canvas, bx, by, bz, blocks::SPRUCE_LEAVES);
                }
            }
        }

        if radius >= limit {
            radius = if shrink { 1 } else { 0 };
            shrink = true;
            limit = (limit + 1).min(widest);
        } else {
            radius += 1;
        }
    }

    for by in y..y + height - random.next_int_bounded(3) {
        log_at(canvas, x, by, z, blocks::SPRUCE_LOG);
    }

    true
}

/// A tall, thin spruce with its crown near the top.
fn tall_spruce(canvas: &mut impl Canvas, random: &mut JavaRandom, x: i32, y: i32, z: i32) -> bool {
    let height = random.next_int_bounded(5) + 7;
    let bare = height - random.next_int_bounded(2) - 3;
    let widest = 1 + random.next_int_bounded(height - bare + 1);

    if !fits(canvas, x, y, z, height, widest) {
        return false;
    }

    plant_dirt(canvas, x, y - 1, z);

    let mut radius = 0;

    for by in (y + bare..=y + height).rev() {
        for bx in x - radius..=x + radius {
            for bz in z - radius..=z + radius {
                if (bx - x).abs() != radius || (bz - z).abs() != radius || radius <= 0 {
                    leaves_at(canvas, bx, by, bz, blocks::SPRUCE_LEAVES);
                }
            }
        }

        if radius >= 1 && by == y + bare + 1 {
            radius -= 1;
        } else if radius < widest {
            radius += 1;
        }
    }

    for by in y..y + height - 1 {
        log_at(canvas, x, by, z, blocks::SPRUCE_LOG);
    }

    true
}

/// A flat, wide crown, like acacia and dark oak have.
fn flat_crown(canvas: &mut impl Canvas, random: &mut JavaRandom, x: i32, y: i32, z: i32, leaves: Block, radius: i32) {
    for (layer, reach) in [(0, radius), (1, radius - 1)] {
        for bx in x - reach..=x + reach {
            for bz in z - reach..=z + reach {
                let (dx, dz) = ((bx - x).abs(), (bz - z).abs());

                if dx == reach && dz == reach && (layer == 1 || random.next_int_bounded(2) == 0) {
                    continue;
                }

                leaves_at(canvas, bx, y + layer, bz, leaves);
            }
        }
    }
}

/// An acacia: the trunk bends to one side and ends in a flat crown.
fn acacia(canvas: &mut impl Canvas, random: &mut JavaRandom, x: i32, y: i32, z: i32) -> bool {
    let height = 5 + random.next_int_bounded(3);

    if !fits(canvas, x, y, z, height, 1) {
        return false;
    }

    plant_dirt(canvas, x, y - 1, z);

    let (dx, dz) = [(1, 0), (-1, 0), (0, 1), (0, -1)][random.next_int_bounded(4) as usize];
    let bend = height - random.next_int_bounded(3) - 1;
    let (mut bx, mut bz) = (x, z);

    for by in y..y + height {
        if by >= y + bend {
            bx += dx;
            bz += dz;
        }

        log_at(canvas, bx, by, bz, blocks::ACACIA_LOG);
    }

    flat_crown(canvas, random, bx, y + height - 1, bz, blocks::ACACIA_LEAVES, 3);
    true
}

/// A dark oak: a thick trunk of four logs under a heavy crown.
fn dark_oak(canvas: &mut impl Canvas, random: &mut JavaRandom, x: i32, y: i32, z: i32) -> bool {
    let height = 6 + random.next_int_bounded(3);

    for (ox, oz) in [(0, 0), (1, 0), (0, 1), (1, 1)] {
        if !blocks::is_soil(canvas.get(x + ox, y - 1, z + oz)) {
            return false;
        }
    }

    if !fits(canvas, x, y, z, height, 2) {
        return false;
    }

    for (ox, oz) in [(0, 0), (1, 0), (0, 1), (1, 1)] {
        plant_dirt(canvas, x + ox, y - 1, z + oz);

        for by in y..y + height {
            log_at(canvas, x + ox, by, z + oz, blocks::DARK_OAK_LOG);
        }
    }

    flat_crown(canvas, random, x, y + height - 2, z, blocks::DARK_OAK_LEAVES, 4);
    flat_crown(canvas, random, x + 1, y + height - 2, z + 1, blocks::DARK_OAK_LEAVES, 4);
    flat_crown(canvas, random, x, y + height, z, blocks::DARK_OAK_LEAVES, 2);
    true
}

/// A cherry tree: a wide pink crown with leaves hanging below its edge.
fn cherry(canvas: &mut impl Canvas, random: &mut JavaRandom, x: i32, y: i32, z: i32) -> bool {
    let height = 5 + random.next_int_bounded(3);

    if !fits(canvas, x, y, z, height, 3) {
        return false;
    }

    plant_dirt(canvas, x, y - 1, z);

    for by in y..y + height {
        log_at(canvas, x, by, z, blocks::CHERRY_LOG);
    }

    let top = y + height;

    for by in top - 2..=top + 1 {
        let reach = if by == top + 1 { 2 } else { 4 };

        for bx in x - reach..=x + reach {
            for bz in z - reach..=z + reach {
                let distance = (bx - x) * (bx - x) + (bz - z) * (bz - z);

                if distance <= reach * reach - 1 + random.next_int_bounded(2) {
                    leaves_at(canvas, bx, by, bz, blocks::CHERRY_LEAVES);

                    //the hanging fringe
                    if by == top - 2 && distance >= (reach - 1) * (reach - 1) && random.next_int_bounded(3) == 0 {
                        leaves_at(canvas, bx, by - 1, bz, blocks::CHERRY_LEAVES);
                    }
                }
            }
        }
    }

    true
}

/// A jungle tree: tall, with vines down the trunk.
fn jungle(canvas: &mut impl Canvas, random: &mut JavaRandom, x: i32, y: i32, z: i32) -> bool {
    let height = 4 + random.next_int_bounded(7) + if random.next_int_bounded(3) == 0 { 6 } else { 0 };

    if !round_tree(canvas, random, x, y, z, blocks::JUNGLE_LOG, blocks::JUNGLE_LEAVES, height, 3, false) {
        return false;
    }

    for by in y + 1..y + height - 1 {
        for (dx, dz, vine) in [(1, 0, blocks::VINE_WEST), (-1, 0, blocks::VINE_EAST), (0, 1, blocks::VINE_NORTH), (0, -1, blocks::VINE_SOUTH)] {
            if random.next_int_bounded(3) == 0 && canvas.get(x + dx, by, z + dz) == blocks::AIR {
                canvas.set(x + dx, by, z + dz, vine);
            }
        }
    }

    true
}

/// A mangrove: its trunk stands on roots above the water line.
fn mangrove(canvas: &mut impl Canvas, random: &mut JavaRandom, x: i32, y: i32, z: i32) -> bool {
    let height = 5 + random.next_int_bounded(4);

    if !round_tree(canvas, random, x, y + 1, z, blocks::MANGROVE_LOG, blocks::MANGROVE_LEAVES, height, 3, true) {
        return round_tree(canvas, random, x, y, z, blocks::MANGROVE_LOG, blocks::MANGROVE_LEAVES, height, 3, true);
    }

    for (dx, dz) in [(1, 0), (-1, 0), (0, 1), (0, -1)] {
        if random.next_int_bounded(3) != 0 {
            log_at(canvas, x + dx, y, z + dz, blocks::MANGROVE_LOG);
        }
    }

    log_at(canvas, x, y, z, blocks::MANGROVE_LOG);
    true
}

/// Grows a tree of `kind` with its trunk at x z, standing on the ground at y - 1.
pub fn tree(canvas: &mut impl Canvas, random: &mut JavaRandom, kind: Tree, x: i32, y: i32, z: i32) -> bool {
    match kind {
        Tree::Oak => {
            let height = random.next_int_bounded(3) + 4;
            round_tree(canvas, random, x, y, z, blocks::OAK_LOG, blocks::OAK_LEAVES, height, 2, false)
        }
        Tree::Birch => {
            let height = random.next_int_bounded(3) + 5;
            round_tree(canvas, random, x, y, z, blocks::BIRCH_LOG, blocks::BIRCH_LEAVES, height, 2, false)
        }
        Tree::Swamp => {
            let height = random.next_int_bounded(4) + 5;
            round_tree(canvas, random, x, y, z, blocks::OAK_LOG, blocks::OAK_LEAVES, height, 3, true)
        }
        Tree::Spruce => spruce(canvas, random, x, y, z),
        Tree::TallSpruce => tall_spruce(canvas, random, x, y, z),
        Tree::Jungle => jungle(canvas, random, x, y, z),
        Tree::Acacia => acacia(canvas, random, x, y, z),
        Tree::DarkOak => dark_oak(canvas, random, x, y, z),
        Tree::Cherry => cherry(canvas, random, x, y, z),
        Tree::Mangrove => mangrove(canvas, random, x, y, z),
    }
}

/// Places small plants in `patches` patches, each at a random spot of the 16 x 16 area from
/// x z, `per_patch` tries around it. Plants stand on soil or on `extra_ground`.
#[allow(clippy::too_many_arguments)]
pub fn scatter(canvas: &mut impl Canvas, random: &mut JavaRandom, x: i32, z: i32, patches: u32, per_patch: u32, mut plant: impl FnMut(&mut JavaRandom) -> Block, extra_ground: &[Block]) {
    for _ in 0..patches {
        let (px, pz) = (x + random.next_int_bounded(16), z + random.next_int_bounded(16));

        for _ in 0..per_patch {
            let bx = px + random.next_int_bounded(4) - random.next_int_bounded(4);
            let bz = pz + random.next_int_bounded(4) - random.next_int_bounded(4);
            let by = canvas.ground(bx, bz);
            let below = canvas.get(bx, by - 1, bz);

            if by < canvas.height() && canvas.get(bx, by, bz) == blocks::AIR && (blocks::is_soil(below) || extra_ground.contains(&below)) {
                let block = plant(random);
                canvas.set(bx, by, bz, block);
            }
        }
    }
}

/// Like scatter, for two block tall plants.
pub fn scatter_tall(canvas: &mut impl Canvas, random: &mut JavaRandom, x: i32, z: i32, tries: u32, bottom: Block, top: Block) {
    let (px, pz) = (x + random.next_int_bounded(16), z + random.next_int_bounded(16));

    for _ in 0..tries {
        let bx = px + random.next_int_bounded(6) - random.next_int_bounded(6);
        let bz = pz + random.next_int_bounded(6) - random.next_int_bounded(6);
        let by = canvas.ground(bx, bz);

        if by + 1 < canvas.height() && canvas.get(bx, by, bz) == blocks::AIR && canvas.get(bx, by + 1, bz) == blocks::AIR && blocks::is_soil(canvas.get(bx, by - 1, bz)) {
            canvas.set(bx, by, bz, bottom);
            canvas.set(bx, by + 1, bz, top);
        }
    }
}

fn touches_solid(canvas: &impl Canvas, x: i32, y: i32, z: i32) -> bool {
    [(1, 0), (-1, 0), (0, 1), (0, -1)].iter().any(|(dx, dz)| blocks::is_solid(canvas.get(x + dx, y, z + dz)))
}

/// Cacti on sand, one to three high, never next to anything solid.
pub fn cacti(canvas: &mut impl Canvas, random: &mut JavaRandom, x: i32, z: i32, tries: u32) {
    for _ in 0..tries {
        let bx = x + random.next_int_bounded(16);
        let bz = z + random.next_int_bounded(16);
        let by = canvas.ground(bx, bz);

        if !matches!(canvas.get(bx, by - 1, bz), blocks::SAND | blocks::RED_SAND) {
            continue;
        }

        let bound = random.next_int_bounded(3) + 1;

        for step in 0..1 + random.next_int_bounded(bound) {
            if canvas.get(bx, by + step, bz) != blocks::AIR || touches_solid(canvas, bx, by + step, bz) {
                break;
            }

            canvas.set(bx, by + step, bz, blocks::CACTUS);
        }
    }
}

/// Sugar cane on the banks of water.
pub fn sugar_cane(canvas: &mut impl Canvas, random: &mut JavaRandom, x: i32, z: i32, tries: u32) {
    for _ in 0..tries {
        let bx = x + random.next_int_bounded(16);
        let bz = z + random.next_int_bounded(16);
        let by = canvas.ground(bx, bz);
        let below = canvas.get(bx, by - 1, bz);

        if canvas.get(bx, by, bz) != blocks::AIR || !(blocks::is_soil(below) || below == blocks::SAND) {
            continue;
        }

        if ![(1, 0), (-1, 0), (0, 1), (0, -1)].iter().any(|(dx, dz)| canvas.get(bx + dx, by - 1, bz + dz) == blocks::WATER) {
            continue;
        }

        let bound = random.next_int_bounded(3) + 1;

        for step in 0..2 + random.next_int_bounded(bound) {
            if canvas.get(bx, by + step, bz) == blocks::AIR {
                canvas.set(bx, by + step, bz, blocks::SUGAR_CANE);
            }
        }
    }
}

/// Stalks of bamboo with leaves at the top.
pub fn bamboo(canvas: &mut impl Canvas, random: &mut JavaRandom, x: i32, z: i32, tries: u32) {
    for _ in 0..tries {
        let bx = x + random.next_int_bounded(16);
        let bz = z + random.next_int_bounded(16);
        let by = canvas.ground(bx, bz);

        if !blocks::is_soil(canvas.get(bx, by - 1, bz)) {
            continue;
        }

        let height = 4 + random.next_int_bounded(9);

        for step in 0..height {
            if canvas.get(bx, by + step, bz) != blocks::AIR {
                break;
            }

            canvas.set(bx, by + step, bz, if step >= height - 2 { blocks::BAMBOO_TOP } else { blocks::BAMBOO });
        }
    }
}

/// A spike of packed ice on the snow.
pub fn ice_spike(canvas: &mut impl Canvas, random: &mut JavaRandom, x: i32, z: i32) {
    let y = canvas.ground(x, z);

    if canvas.get(x, y - 1, z) != blocks::SNOW {
        return;
    }

    let height = 7 + random.next_int_bounded(8);
    let radius = 1 + random.next_int_bounded(2);

    for step in 0..height {
        let reach = (radius as f64 * (1.0 - step as f64 / height as f64)).ceil() as i32;

        for bx in x - reach..=x + reach {
            for bz in z - reach..=z + reach {
                if (bx - x) * (bx - x) + (bz - z) * (bz - z) <= reach * reach && blocks::is_replaceable(canvas.get(bx, y + step, bz)) {
                    canvas.set(bx, y + step, bz, blocks::PACKED_ICE);
                }
            }
        }
    }
}
