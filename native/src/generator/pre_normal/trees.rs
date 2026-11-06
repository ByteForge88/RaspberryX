//! The trees and huge mushrooms of 1.8, each the way its generator grows it.

use std::f64::consts::PI as PI64;
use std::f32::consts::PI;

use super::world::{is_dirt, is_full, is_grass_or_dirt, View};
use super::{cos, floor, sin};
use crate::generator::blocks::{self, Block};
use crate::random::JavaRandom;

#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub enum Tree {
    Oak,
    BigOak,
    /// oak shaped, with its height, log and leaves given, and vines and cocoa when `jungle`
    Small { height: i32, log: Block, leaves: Block, jungle: bool },
    Birch,
    TallBirch,
    Spruce,
    Pine,
    MegaPine,
    MegaSpruce,
    MegaJungle,
    Shrub,
    Swamp,
    Acacia,
    DarkOak,
}

/// The four sides the game walks for horizontal facings: north, east, south, west.
const HORIZONTAL: [(i32, i32); 4] = [(0, -1), (1, 0), (0, 1), (-1, 0)];

fn random_facing(random: &mut JavaRandom) -> (i32, i32) {
    HORIZONTAL[random.next_int_bounded(4) as usize]
}

/// What a tree may grow its trunk and leaves through.
fn can_grow_into(block: Block) -> bool {
    block == blocks::AIR || blocks::is_leaves(block) || is_grass_or_dirt(block) || blocks::is_log(block) || blocks::is_vine(block)
}

fn air_or_leaves(block: Block) -> bool {
    block == blocks::AIR || blocks::is_leaves(block)
}

/// Turns the ground under a trunk to dirt, leaving coarse dirt and podzol be.
fn dirt_under(view: &mut View, x: i32, y: i32, z: i32) {
    if !is_dirt(view.get(x, y, z)) {
        view.set(x, y, z, blocks::DIRT);
    }
}

/// The game's look before growing: a column of growable space, wider near the top.
fn has_room(view: &View, x: i32, y: i32, z: i32, height: i32, top_radius: i32, fits: impl Fn(Block) -> bool) -> bool {
    if y < 1 || y + height + 1 > 256 {
        return false;
    }

    for by in y..=y + 1 + height {
        let mut radius = 1;

        if by == y {
            radius = 0;
        }

        if by >= y + 1 + height - 2 {
            radius = top_radius;
        }

        for bx in x - radius..=x + radius {
            for bz in z - radius..=z + radius {
                if !(0..256).contains(&by) || !fits(view.get(bx, by, bz)) {
                    return false;
                }
            }
        }
    }

    true
}

const VINE_EAST: Block = blocks::VINE_EAST;
const VINE_WEST: Block = blocks::VINE_WEST;
const VINE_SOUTH: Block = blocks::VINE_SOUTH;
const VINE_NORTH: Block = blocks::VINE_NORTH;

/// A vine and up to four more under it while there is air.
fn hanging_vine(view: &mut View, x: i32, y: i32, z: i32, block: Block) {
    view.set(x, y, z, block);
    let mut by = y - 1;
    let mut left = 4;

    while view.is_air(x, by, z) && left > 0 {
        view.set(x, by, z, block);
        by -= 1;
        left -= 1;
    }
}

pub fn grow(view: &mut View, random: &mut JavaRandom, tree: Tree, x: i32, y: i32, z: i32) -> bool {
    match tree {
        Tree::Oak => small(view, random, x, y, z, 4, blocks::OAK_LOG, blocks::OAK_LEAVES, false),
        Tree::Small { height, log, leaves, jungle } => small(view, random, x, y, z, height, log, leaves, jungle),
        Tree::BigOak => big_oak(view, random, x, y, z),
        Tree::Birch => birch(view, random, x, y, z, false),
        Tree::TallBirch => birch(view, random, x, y, z, true),
        Tree::Spruce => spruce(view, random, x, y, z),
        Tree::Pine => pine(view, random, x, y, z),
        Tree::MegaPine => mega_pine(view, random, x, y, z, false),
        Tree::MegaSpruce => mega_pine(view, random, x, y, z, true),
        Tree::MegaJungle => mega_jungle(view, random, x, y, z),
        Tree::Shrub => shrub(view, random, x, y, z),
        Tree::Swamp => swamp(view, random, x, y, z),
        Tree::Acacia => acacia(view, random, x, y, z),
        Tree::DarkOak => dark_oak(view, random, x, y, z),
    }
}

/// What a tree does after it grew; mega pines spread podzol.
pub fn after(view: &mut View, random: &mut JavaRandom, tree: Tree, x: i32, y: i32, z: i32) {
    if matches!(tree, Tree::MegaPine | Tree::MegaSpruce) {
        for (dx, dz) in [(-1, -1), (2, -1), (-1, 2), (2, 2)] {
            podzol_patch(view, x + dx, y, z + dz);
        }

        for _ in 0..5 {
            let j = random.next_int_bounded(64);
            let (k, l) = (j % 8, j / 8);

            if k == 0 || k == 7 || l == 0 || l == 7 {
                podzol_patch(view, x - 3 + k, y, z - 3 + l);
            }
        }
    }
}

fn podzol_patch(view: &mut View, x: i32, y: i32, z: i32) {
    for i in -2..=2 {
        for j in -2..=2 {
            if i32::abs(i) != 2 || i32::abs(j) != 2 {
                for dy in (-3..=2).rev() {
                    let block = view.get(x + i, y + dy, z + j);

                    if block == blocks::GRASS_BLOCK || is_dirt(block) {
                        view.set(x + i, y + dy, z + j, blocks::PODZOL);
                        break;
                    }

                    if block != blocks::AIR && dy < 0 {
                        break;
                    }
                }
            }
        }
    }
}

#[allow(clippy::too_many_arguments)]
fn small(view: &mut View, random: &mut JavaRandom, x: i32, y: i32, z: i32, min_height: i32, log: Block, leaves: Block, jungle: bool) -> bool {
    let height = random.next_int_bounded(3) + min_height;

    if !has_room(view, x, y, z, height, 2, can_grow_into) {
        return false;
    }

    let below = view.get(x, y - 1, z);

    if !is_grass_or_dirt(below) || y >= 256 - height - 1 {
        return false;
    }

    dirt_under(view, x, y - 1, z);

    for by in y - 3 + height..=y + height {
        let offset = by - (y + height);
        let radius = 1 - offset / 2;

        for bx in x - radius..=x + radius {
            for bz in z - radius..=z + radius {
                if i32::abs(bx - x) != radius || i32::abs(bz - z) != radius || (random.next_int_bounded(2) != 0 && offset != 0) {
                    let block = view.get(bx, by, bz);

                    if block == blocks::AIR || blocks::is_leaves(block) || blocks::is_vine(block) {
                        view.set(bx, by, bz, leaves);
                    }
                }
            }
        }
    }

    for i in 0..height {
        let block = view.get(x, y + i, z);

        if block == blocks::AIR || blocks::is_leaves(block) || blocks::is_vine(block) {
            view.set(x, y + i, z, log);

            if jungle && i > 0 {
                if random.next_int_bounded(3) > 0 && view.is_air(x - 1, y + i, z) {
                    view.set(x - 1, y + i, z, VINE_EAST);
                }
                if random.next_int_bounded(3) > 0 && view.is_air(x + 1, y + i, z) {
                    view.set(x + 1, y + i, z, VINE_WEST);
                }
                if random.next_int_bounded(3) > 0 && view.is_air(x, y + i, z - 1) {
                    view.set(x, y + i, z - 1, VINE_SOUTH);
                }
                if random.next_int_bounded(3) > 0 && view.is_air(x, y + i, z + 1) {
                    view.set(x, y + i, z + 1, VINE_NORTH);
                }
            }
        }
    }

    if jungle {
        for by in y - 3 + height..=y + height {
            let offset = by - (y + height);
            let radius = 2 - offset / 2;

            for bx in x - radius..=x + radius {
                for bz in z - radius..=z + radius {
                    if blocks::is_leaves(view.get(bx, by, bz)) {
                        if random.next_int_bounded(4) == 0 && view.is_air(bx - 1, by, bz) {
                            hanging_vine(view, bx - 1, by, bz, VINE_EAST);
                        }
                        if random.next_int_bounded(4) == 0 && view.is_air(bx + 1, by, bz) {
                            hanging_vine(view, bx + 1, by, bz, VINE_WEST);
                        }
                        if random.next_int_bounded(4) == 0 && view.is_air(bx, by, bz - 1) {
                            hanging_vine(view, bx, by, bz - 1, VINE_SOUTH);
                        }
                        if random.next_int_bounded(4) == 0 && view.is_air(bx, by, bz + 1) {
                            hanging_vine(view, bx, by, bz + 1, VINE_NORTH);
                        }
                    }
                }
            }
        }

        if random.next_int_bounded(5) == 0 && height > 5 {
            for level in 0..2 {
                for (dx, dz) in HORIZONTAL.iter() {
                    if random.next_int_bounded(4 - level) == 0 {
                        let age = random.next_int_bounded(3) as u8;
                        //the pod sits on the far side and faces back at the trunk
                        let (px, pz) = (x - dx, z - dz);
                        view.set(px, y + height - 5 + level, pz, blocks::cocoa(cocoa_direction(*dx, *dz), age));
                    }
                }
            }
        }
    }

    true
}

/// Bedrock's cocoa direction for a pod facing the trunk along dx, dz.
fn cocoa_direction(dx: i32, dz: i32) -> u8 {
    match (dx, dz) {
        (0, 1) => 0,
        (-1, 0) => 1,
        (0, -1) => 2,
        _ => 3,
    }
}

fn birch(view: &mut View, random: &mut JavaRandom, x: i32, y: i32, z: i32, tall: bool) -> bool {
    let mut height = random.next_int_bounded(3) + 5;

    if tall {
        height += random.next_int_bounded(7);
    }

    if !has_room(view, x, y, z, height, 2, can_grow_into) {
        return false;
    }

    if !is_grass_or_dirt(view.get(x, y - 1, z)) || y >= 256 - height - 1 {
        return false;
    }

    dirt_under(view, x, y - 1, z);

    for by in y - 3 + height..=y + height {
        let offset = by - (y + height);
        let radius = 1 - offset / 2;

        for bx in x - radius..=x + radius {
            for bz in z - radius..=z + radius {
                if i32::abs(bx - x) != radius || i32::abs(bz - z) != radius || (random.next_int_bounded(2) != 0 && offset != 0) {
                    if air_or_leaves(view.get(bx, by, bz)) {
                        view.set(bx, by, bz, blocks::BIRCH_LEAVES);
                    }
                }
            }
        }
    }

    for i in 0..height {
        if air_or_leaves(view.get(x, y + i, z)) {
            view.set(x, y + i, z, blocks::BIRCH_LOG);
        }
    }

    true
}

fn spruce(view: &mut View, random: &mut JavaRandom, x: i32, y: i32, z: i32) -> bool {
    let height = random.next_int_bounded(4) + 6;
    let bare = 1 + random.next_int_bounded(2);
    let crown = height - bare;
    let widest = 2 + random.next_int_bounded(2);

    if y < 1 || y + height + 1 > 256 {
        return false;
    }

    for by in y..=y + 1 + height {
        let radius = if by - y < bare { 0 } else { widest };

        for bx in x - radius..=x + radius {
            for bz in z - radius..=z + radius {
                if !(0..256).contains(&by) || !air_or_leaves(view.get(bx, by, bz)) {
                    return false;
                }
            }
        }
    }

    if !is_grass_or_dirt(view.get(x, y - 1, z)) || y >= 256 - height - 1 {
        return false;
    }

    dirt_under(view, x, y - 1, z);

    let mut radius = random.next_int_bounded(2);
    let mut limit = 1;
    let mut start = 0;

    for i in 0..=crown {
        let by = y + height - i;

        for bx in x - radius..=x + radius {
            for bz in z - radius..=z + radius {
                if (i32::abs(bx - x) != radius || i32::abs(bz - z) != radius || radius <= 0) && !is_full(view.get(bx, by, bz)) {
                    view.set(bx, by, bz, blocks::SPRUCE_LEAVES);
                }
            }
        }

        if radius >= limit {
            radius = start;
            start = 1;
            limit += 1;

            if limit > widest {
                limit = widest;
            }
        } else {
            radius += 1;
        }
    }

    let short = random.next_int_bounded(3);

    for i in 0..height - short {
        if air_or_leaves(view.get(x, y + i, z)) {
            view.set(x, y + i, z, blocks::SPRUCE_LOG);
        }
    }

    true
}

fn pine(view: &mut View, random: &mut JavaRandom, x: i32, y: i32, z: i32) -> bool {
    let height = random.next_int_bounded(5) + 7;
    let bare = height - random.next_int_bounded(2) - 3;
    let crown = height - bare;
    let widest = 1 + random.next_int_bounded(crown + 1);

    if y < 1 || y + height + 1 > 256 {
        return false;
    }

    for by in y..=y + 1 + height {
        let radius = if by - y < bare { 0 } else { widest };

        for bx in x - radius..=x + radius {
            for bz in z - radius..=z + radius {
                if !(0..256).contains(&by) || !can_grow_into(view.get(bx, by, bz)) {
                    return false;
                }
            }
        }
    }

    if !is_grass_or_dirt(view.get(x, y - 1, z)) || y >= 256 - height - 1 {
        return false;
    }

    dirt_under(view, x, y - 1, z);

    let mut radius = 0;
    let mut by = y + height;

    while by >= y + bare {
        for bx in x - radius..=x + radius {
            for bz in z - radius..=z + radius {
                if (i32::abs(bx - x) != radius || i32::abs(bz - z) != radius || radius <= 0) && !is_full(view.get(bx, by, bz)) {
                    view.set(bx, by, bz, blocks::SPRUCE_LEAVES);
                }
            }
        }

        if radius >= 1 && by == y + bare + 1 {
            radius -= 1;
        } else if radius < widest {
            radius += 1;
        }

        by -= 1;
    }

    for i in 0..height - 1 {
        if air_or_leaves(view.get(x, y + i, z)) {
            view.set(x, y + i, z, blocks::SPRUCE_LOG);
        }
    }

    true
}

/// The height of a 2 x 2 tree: base, plus 0 to 2, plus up to `extra` - 1.
fn huge_height(random: &mut JavaRandom, base: i32, extra: i32) -> i32 {
    let mut height = random.next_int_bounded(3) + base;

    if extra > 1 {
        height += random.next_int_bounded(extra);
    }

    height
}

fn huge_has_room(view: &mut View, x: i32, y: i32, z: i32, height: i32) -> bool {
    if y < 1 || y + height + 1 > 256 {
        return false;
    }

    for i in 0..=1 + height {
        let radius = if i == 0 { 1 } else { 2 };

        for dx in -radius..=radius {
            for dz in -radius..=radius {
                if y + i < 0 || y + i >= 256 || !can_grow_into(view.get(x + dx, y + i, z + dz)) {
                    return false;
                }
            }
        }
    }

    let below = view.get(x, y - 1, z);

    if !is_grass_or_dirt(below) || y < 2 {
        return false;
    }

    for (dx, dz) in [(0, 0), (1, 0), (0, 1), (1, 1)] {
        dirt_under(view, x + dx, y - 1, z + dz);
    }

    true
}

/// A round layer of leaves around a 2 x 2 trunk.
fn wide_layer(view: &mut View, x: i32, y: i32, z: i32, radius: i32, leaves: Block) {
    let square = radius * radius;

    for j in -radius..=radius + 1 {
        for k in -radius..=radius + 1 {
            let (l, m) = (j - 1, k - 1);

            if j * j + k * k <= square || l * l + m * m <= square || j * j + m * m <= square || l * l + k * k <= square {
                if air_or_leaves(view.get(x + j, y, z + k)) {
                    view.set(x + j, y, z + k, leaves);
                }
            }
        }
    }
}

/// A round layer of leaves around one block.
fn round_layer(view: &mut View, x: i32, y: i32, z: i32, radius: i32, leaves: Block) {
    let square = radius * radius;

    for j in -radius..=radius {
        for k in -radius..=radius {
            if j * j + k * k <= square && air_or_leaves(view.get(x + j, y, z + k)) {
                view.set(x + j, y, z + k, leaves);
            }
        }
    }
}

fn mega_pine(view: &mut View, random: &mut JavaRandom, x: i32, y: i32, z: i32, spruce: bool) -> bool {
    let height = huge_height(random, 13, 15);

    if !huge_has_room(view, x, y, z, height) {
        return false;
    }

    let top = y + height;
    let crown = random.next_int_bounded(5) + if spruce { 13 } else { 3 };
    let mut last = 0;

    for by in top - crown..=top {
        let depth = top - by;
        let radius = (depth as f32 / crown as f32 * 3.5).floor() as i32;
        let extra = (depth > 0 && radius == last && (by & 1) == 0) as i32;
        wide_layer(view, x, by, z, radius + extra, blocks::SPRUCE_LEAVES);
        last = radius;
    }

    for i in 0..height {
        for (dx, dz) in [(0, 0), (1, 0), (1, 1), (0, 1)] {
            if (dx, dz) != (0, 0) && i >= height - 1 {
                continue;
            }

            if air_or_leaves(view.get(x + dx, y + i, z + dz)) {
                view.set(x + dx, y + i, z + dz, blocks::SPRUCE_LOG);
            }
        }
    }

    true
}

fn maybe_vine(view: &mut View, random: &mut JavaRandom, x: i32, y: i32, z: i32, block: Block) {
    if random.next_int_bounded(3) > 0 && view.is_air(x, y, z) {
        view.set(x, y, z, block);
    }
}

fn mega_jungle(view: &mut View, random: &mut JavaRandom, x: i32, y: i32, z: i32) -> bool {
    let height = huge_height(random, 10, 20);

    if !huge_has_room(view, x, y, z, height) {
        return false;
    }

    for dy in -2..=0 {
        wide_layer(view, x, y + height + dy, z, 2 + 1 - dy, blocks::JUNGLE_LEAVES);
    }

    let mut by = y + height - 2 - random.next_int_bounded(4);

    while by > y + height / 2 {
        let angle = random.next_float() * PI * 2.0;
        let mut bx = x + (0.5 + cos(angle) * 4.0) as i32;
        let mut bz = z + (0.5 + sin(angle) * 4.0) as i32;

        for i in 0..5 {
            bx = x + (1.5 + cos(angle) * i as f32) as i32;
            bz = z + (1.5 + sin(angle) * i as f32) as i32;
            view.set(bx, by - 3 + i / 2, bz, blocks::JUNGLE_LOG);
        }

        let spread = 1 + random.next_int_bounded(2);

        for ly in by - spread..=by {
            round_layer(view, bx, ly, bz, 1 - (ly - by), blocks::JUNGLE_LEAVES);
        }

        by -= 2 + random.next_int_bounded(4);
    }

    for i in 0..height {
        let by = y + i;

        if can_grow_into(view.get(x, by, z)) {
            view.set(x, by, z, blocks::JUNGLE_LOG);

            if i > 0 {
                maybe_vine(view, random, x - 1, by, z, VINE_EAST);
                maybe_vine(view, random, x, by, z - 1, VINE_SOUTH);
            }
        }

        if i < height - 1 {
            if can_grow_into(view.get(x + 1, by, z)) {
                view.set(x + 1, by, z, blocks::JUNGLE_LOG);

                if i > 0 {
                    maybe_vine(view, random, x + 2, by, z, VINE_WEST);
                    maybe_vine(view, random, x + 1, by, z - 1, VINE_SOUTH);
                }
            }

            if can_grow_into(view.get(x + 1, by, z + 1)) {
                view.set(x + 1, by, z + 1, blocks::JUNGLE_LOG);

                if i > 0 {
                    maybe_vine(view, random, x + 2, by, z + 1, VINE_WEST);
                    maybe_vine(view, random, x + 1, by, z + 2, VINE_NORTH);
                }
            }

            if can_grow_into(view.get(x, by, z + 1)) {
                view.set(x, by, z + 1, blocks::JUNGLE_LOG);

                if i > 0 {
                    maybe_vine(view, random, x - 1, by, z + 1, VINE_EAST);
                    maybe_vine(view, random, x, by, z + 2, VINE_NORTH);
                }
            }
        }
    }

    true
}

fn shrub(view: &mut View, random: &mut JavaRandom, x: i32, mut y: i32, z: i32) -> bool {
    while air_or_leaves(view.get(x, y, z)) && y > 0 {
        y -= 1;
    }

    if !matches!(view.get(x, y, z), blocks::DIRT | blocks::COARSE_DIRT | blocks::PODZOL | blocks::GRASS_BLOCK) {
        return true;
    }

    y += 1;
    view.set(x, y, z, blocks::JUNGLE_LOG);

    for by in y..=y + 2 {
        let radius = 2 - (by - y);

        for bx in x - radius..=x + radius {
            for bz in z - radius..=z + radius {
                if (i32::abs(bx - x) != radius || i32::abs(bz - z) != radius || random.next_int_bounded(2) != 0) && !is_full(view.get(bx, by, bz)) {
                    view.set(bx, by, bz, blocks::OAK_LEAVES);
                }
            }
        }
    }

    true
}

fn swamp(view: &mut View, random: &mut JavaRandom, x: i32, mut y: i32, z: i32) -> bool {
    let height = random.next_int_bounded(4) + 5;

    while view.get(x, y - 1, z) == blocks::WATER {
        y -= 1;
    }

    if y < 1 || y + height + 1 > 256 {
        return false;
    }

    for by in y..=y + 1 + height {
        let mut radius = 1;

        if by == y {
            radius = 0;
        }

        if by >= y + 1 + height - 2 {
            radius = 3;
        }

        for bx in x - radius..=x + radius {
            for bz in z - radius..=z + radius {
                if !(0..256).contains(&by) {
                    return false;
                }

                let block = view.get(bx, by, bz);

                if !air_or_leaves(block) && (block != blocks::WATER || by > y) {
                    return false;
                }
            }
        }
    }

    if !is_grass_or_dirt(view.get(x, y - 1, z)) || y >= 256 - height - 1 {
        return false;
    }

    dirt_under(view, x, y - 1, z);

    for by in y - 3 + height..=y + height {
        let offset = by - (y + height);
        let radius = 2 - offset / 2;

        for bx in x - radius..=x + radius {
            for bz in z - radius..=z + radius {
                if (i32::abs(bx - x) != radius || i32::abs(bz - z) != radius || (random.next_int_bounded(2) != 0 && offset != 0)) && !is_full(view.get(bx, by, bz)) {
                    view.set(bx, by, bz, blocks::OAK_LEAVES);
                }
            }
        }
    }

    for i in 0..height {
        let block = view.get(x, y + i, z);

        if air_or_leaves(block) || block == blocks::WATER {
            view.set(x, y + i, z, blocks::OAK_LOG);
        }
    }

    for by in y - 3 + height..=y + height {
        let offset = by - (y + height);
        let radius = 2 - offset / 2;

        for bx in x - radius..=x + radius {
            for bz in z - radius..=z + radius {
                if blocks::is_leaves(view.get(bx, by, bz)) {
                    if random.next_int_bounded(4) == 0 && view.is_air(bx - 1, by, bz) {
                        hanging_vine(view, bx - 1, by, bz, VINE_EAST);
                    }
                    if random.next_int_bounded(4) == 0 && view.is_air(bx + 1, by, bz) {
                        hanging_vine(view, bx + 1, by, bz, VINE_WEST);
                    }
                    if random.next_int_bounded(4) == 0 && view.is_air(bx, by, bz - 1) {
                        hanging_vine(view, bx, by, bz - 1, VINE_SOUTH);
                    }
                    if random.next_int_bounded(4) == 0 && view.is_air(bx, by, bz + 1) {
                        hanging_vine(view, bx, by, bz + 1, VINE_NORTH);
                    }
                }
            }
        }
    }

    true
}

fn acacia_leaves(view: &mut View, x: i32, y: i32, z: i32) {
    if air_or_leaves(view.get(x, y, z)) {
        view.set(x, y, z, blocks::ACACIA_LEAVES);
    }
}

fn acacia(view: &mut View, random: &mut JavaRandom, x: i32, y: i32, z: i32) -> bool {
    let height = random.next_int_bounded(3) + random.next_int_bounded(3) + 5;

    if !has_room(view, x, y, z, height, 2, can_grow_into) {
        return false;
    }

    if !is_grass_or_dirt(view.get(x, y - 1, z)) || y >= 256 - height - 1 {
        return false;
    }

    dirt_under(view, x, y - 1, z);

    let (fx, fz) = random_facing(random);
    let bend = height - random.next_int_bounded(4) - 1;
    let mut lean = 3 - random.next_int_bounded(3);
    let (mut tx, mut tz) = (x, z);
    let mut top = 0;

    for i in 0..height {
        let by = y + i;

        if i >= bend && lean > 0 {
            tx += fx;
            tz += fz;
            lean -= 1;
        }

        if air_or_leaves(view.get(tx, by, tz)) {
            view.set(tx, by, tz, blocks::ACACIA_LOG);
            top = by;
        }
    }

    for dx in -3..=3 {
        for dz in -3..=3 {
            if i32::abs(dx) != 3 || i32::abs(dz) != 3 {
                acacia_leaves(view, tx + dx, top, tz + dz);
            }
        }
    }

    for dx in -1..=1 {
        for dz in -1..=1 {
            acacia_leaves(view, tx + dx, top + 1, tz + dz);
        }
    }

    for (dx, dz) in [(2, 0), (-2, 0), (0, 2), (0, -2)] {
        acacia_leaves(view, tx + dx, top + 1, tz + dz);
    }

    let (mut tx, mut tz) = (x, z);
    let (sx, sz) = random_facing(random);

    if (sx, sz) != (fx, fz) {
        let mut i = bend - random.next_int_bounded(2) - 1;
        let mut length = 1 + random.next_int_bounded(3);
        let mut top = 0;

        while i < height && length > 0 {
            if i >= 1 {
                let by = y + i;
                tx += sx;
                tz += sz;

                if air_or_leaves(view.get(tx, by, tz)) {
                    view.set(tx, by, tz, blocks::ACACIA_LOG);
                    top = by;
                }
            }

            i += 1;
            length -= 1;
        }

        if top > 0 {
            for dx in -2..=2 {
                for dz in -2..=2 {
                    if i32::abs(dx) != 2 || i32::abs(dz) != 2 {
                        acacia_leaves(view, tx + dx, top, tz + dz);
                    }
                }
            }

            for dx in -1..=1 {
                for dz in -1..=1 {
                    acacia_leaves(view, tx + dx, top + 1, tz + dz);
                }
            }
        }
    }

    true
}

fn dark_log(view: &mut View, x: i32, y: i32, z: i32) {
    if can_grow_into(view.get(x, y, z)) {
        view.set(x, y, z, blocks::DARK_OAK_LOG);
    }
}

fn dark_leaves(view: &mut View, x: i32, y: i32, z: i32) {
    if view.is_air(x, y, z) {
        view.set(x, y, z, blocks::DARK_OAK_LEAVES);
    }
}

fn dark_oak(view: &mut View, random: &mut JavaRandom, x: i32, y: i32, z: i32) -> bool {
    let height = random.next_int_bounded(3) + random.next_int_bounded(2) + 6;

    if y < 1 || y + height + 1 >= 256 {
        return false;
    }

    if !is_grass_or_dirt(view.get(x, y - 1, z)) {
        return false;
    }

    for l in 0..=height + 1 {
        let radius = if l == 0 { 0 } else if l >= height - 1 { 2 } else { 1 };

        for dx in -radius..=radius {
            for dz in -radius..=radius {
                if !can_grow_into(view.get(x + dx, y + l, z + dz)) {
                    return false;
                }
            }
        }
    }

    for (dx, dz) in [(0, 0), (1, 0), (0, 1), (1, 1)] {
        dirt_under(view, x + dx, y - 1, z + dz);
    }

    let (fx, fz) = random_facing(random);
    let bend = height - random.next_int_bounded(4);
    let mut lean = 2 - random.next_int_bounded(3);
    let (mut tx, mut tz) = (x, z);
    let top = y + height - 1;

    for i in 0..height {
        if i >= bend && lean > 0 {
            tx += fx;
            tz += fz;
            lean -= 1;
        }

        let by = y + i;

        if air_or_leaves(view.get(tx, by, tz)) {
            for (dx, dz) in [(0, 0), (1, 0), (0, 1), (1, 1)] {
                dark_log(view, tx + dx, by, tz + dz);
            }
        }
    }

    for i in -2..=0 {
        for l in -2..=0 {
            for dy in [-1, 1] {
                if dy == 1 && !((i > -2 || l > -1) && (i != -1 || l != -2)) {
                    continue;
                }

                dark_leaves(view, tx + i, top + dy, tz + l);
                dark_leaves(view, 1 + tx - i, top + dy, tz + l);
                dark_leaves(view, tx + i, top + dy, 1 + tz - l);
                dark_leaves(view, 1 + tx - i, top + dy, 1 + tz - l);
            }
        }
    }

    if random.next_bool() {
        dark_leaves(view, tx, top + 2, tz);
        dark_leaves(view, tx + 1, top + 2, tz);
        dark_leaves(view, tx + 1, top + 2, tz + 1);
        dark_leaves(view, tx, top + 2, tz + 1);
    }

    for i in -3..=4 {
        for l in -3..=4 {
            if (i != -3 || l != -3) && (i != -3 || l != 4) && (i != 4 || l != -3) && (i != 4 || l != 4) && (i32::abs(i) < 3 || i32::abs(l) < 3) {
                dark_leaves(view, tx + i, top, tz + l);
            }
        }
    }

    for i in -1..=2 {
        for l in -1..=2 {
            if (i < 0 || i > 1 || l < 0 || l > 1) && random.next_int_bounded(3) <= 0 {
                let length = random.next_int_bounded(3) + 2;

                for k in 0..length {
                    dark_log(view, x + i, top - k - 1, z + l);
                }

                for dx in -1..=1 {
                    for dz in -1..=1 {
                        dark_leaves(view, tx + i + dx, top, tz + l + dz);
                    }
                }

                for dx in -2..=2 {
                    for dz in -2..=2 {
                        if i32::abs(dx) != 2 || i32::abs(dz) != 2 {
                            dark_leaves(view, tx + i + dx, top - 1, tz + l + dz);
                        }
                    }
                }
            }
        }
    }

    true
}

/// The branchy big oak. It draws from a random of its own, seeded from the decorator's.
fn big_oak(view: &mut View, random: &mut JavaRandom, x: i32, y: i32, z: i32) -> bool {
    let mut random = JavaRandom::new(random.next_long());
    let leaf_distance = 5;
    let mut height_limit = 5 + random.next_int_bounded(12);

    let below = view.get(x, y - 1, z);

    if !is_grass_or_dirt(below) {
        return false;
    }

    match block_line(view, (x, y, z), (x, y + height_limit - 1, z)) {
        None => {}
        Some(blocked) if blocked < 6 => return false,
        Some(blocked) => height_limit = blocked,
    }

    let mut height = (height_limit as f64 * 0.618) as i32;

    if height >= height_limit {
        height = height_limit - 1;
    }

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
        let mut size = ((half * half - from * from) as f64).sqrt() as f32;

        if from == 0.0 {
            size = half;
        } else if from.abs() >= half {
            return 0.0;
        }

        size * 0.5
    };

    while k >= 0 {
        let size = layer_size(k);

        if size >= 0.0 {
            for _ in 0..per_layer {
                let distance = 1.0 * size as f64 * (random.next_float() as f64 + 0.328);
                let angle = (random.next_float() * 2.0) as f64 * PI64;
                let nx = floor(x as f64 + distance * angle.sin() + 0.5);
                let nz = floor(z as f64 + distance * angle.cos() + 0.5);
                let node = (nx, floor((y + k - 1) as f64), nz);

                if block_line(view, node, (node.0, node.1 + leaf_distance, node.2)).is_none() {
                    let dx = x - node.0;
                    let dz = z - node.2;
                    let base = node.1 as f64 - ((dx * dx + dz * dz) as f64).sqrt() * 0.381;
                    let base = if base > trunk_top as f64 { trunk_top } else { base as i32 };

                    if block_line(view, (x, base, z), node).is_none() {
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
                    if (i32::abs(dx) as f64 + 0.5).powi(2) + (i32::abs(dz) as f64 + 0.5).powi(2) <= (size * size) as f64 {
                        if air_or_leaves(view.get(nx + dx, ny + i, nz + dz)) {
                            view.set(nx + dx, ny + i, nz + dz, blocks::OAK_LEAVES);
                        }
                    }
                }
            }
        }
    }

    limb(view, (x, y, z), (x, y + height, z));

    for &(node, base) in &nodes {
        let start = (x, base, z);

        if start != node && (base - y) as f64 >= height_limit as f64 * 0.2 {
            limb(view, start, node);
        }
    }

    true
}

fn greatest(dx: i32, dy: i32, dz: i32) -> i32 {
    let (i, j, k) = (dx.abs(), dy.abs(), dz.abs());
    if k > i && k > j { k } else if j > i { j } else { i }
}

fn line_steps(from: (i32, i32, i32), to: (i32, i32, i32)) -> (i32, f32, f32, f32) {
    let (dx, dy, dz) = (to.0 - from.0, to.1 - from.1, to.2 - from.2);
    let steps = greatest(dx, dy, dz);
    (steps, dx as f32 / steps as f32, dy as f32 / steps as f32, dz as f32 / steps as f32)
}

fn line_at(from: (i32, i32, i32), j: i32, fx: f32, fy: f32, fz: f32) -> (i32, i32, i32) {
    (
        from.0 + floor((0.5 + j as f32 * fx) as f64),
        from.1 + floor((0.5 + j as f32 * fy) as f64),
        from.2 + floor((0.5 + j as f32 * fz) as f64),
    )
}

/// How far along the line it is free to grow, None for all the way.
fn block_line(view: &View, from: (i32, i32, i32), to: (i32, i32, i32)) -> Option<i32> {
    let (steps, fx, fy, fz) = line_steps(from, to);

    if steps == 0 {
        return None;
    }

    for j in 0..=steps {
        let (bx, by, bz) = line_at(from, j, fx, fy, fz);

        if !can_grow_into(view.get(bx, by, bz)) {
            return Some(j);
        }
    }

    None
}

fn limb(view: &mut View, from: (i32, i32, i32), to: (i32, i32, i32)) {
    let (steps, fx, fy, fz) = line_steps(from, to);

    for j in 0..=steps {
        let (bx, by, bz) = line_at(from, j, fx, fy, fz);
        view.set(bx, by, bz, blocks::OAK_LOG);
    }
}

/// A huge mushroom, brown or red as the random says.
pub fn huge_mushroom(view: &mut View, random: &mut JavaRandom, x: i32, y: i32, z: i32) -> bool {
    let brown = random.next_bool();
    let height = random.next_int_bounded(3) + 4;

    if y < 1 || y + height + 1 >= 256 {
        return false;
    }

    for by in y..=y + 1 + height {
        let radius = if by <= y + 3 { 0 } else { 3 };

        for bx in x - radius..=x + radius {
            for bz in z - radius..=z + radius {
                if !(0..256).contains(&by) || !air_or_leaves(view.get(bx, by, bz)) {
                    return false;
                }
            }
        }
    }

    if !matches!(view.get(x, y - 1, z), blocks::DIRT | blocks::COARSE_DIRT | blocks::PODZOL | blocks::GRASS_BLOCK | blocks::MYCELIUM) {
        return false;
    }

    let cap = |bits: u8| if brown { blocks::brown_mushroom_block(bits) } else { blocks::red_mushroom_block(bits) };
    let top = y + height;
    let start = if brown { top } else { top - 3 };

    for by in start..=top {
        let mut radius = 1;

        if by < top {
            radius += 1;
        }

        if brown {
            radius = 3;
        }

        let (min_x, max_x, min_z, max_z) = (x - radius, x + radius, z - radius, z + radius);

        for bx in min_x..=max_x {
            for bz in min_z..=max_z {
                let mut bits: i32 = 5;

                if bx == min_x {
                    bits -= 1;
                } else if bx == max_x {
                    bits += 1;
                }

                if bz == min_z {
                    bits -= 3;
                } else if bz == max_z {
                    bits += 3;
                }

                if brown || by < top {
                    if (bx == min_x || bx == max_x) && (bz == min_z || bz == max_z) {
                        continue;
                    }

                    if (bx == x - (radius - 1) && bz == min_z) || (bx == min_x && bz == z - (radius - 1)) {
                        bits = 1;
                    }

                    if (bx == x + (radius - 1) && bz == min_z) || (bx == max_x && bz == z - (radius - 1)) {
                        bits = 3;
                    }

                    if (bx == x - (radius - 1) && bz == max_z) || (bx == min_x && bz == z + (radius - 1)) {
                        bits = 7;
                    }

                    if (bx == x + (radius - 1) && bz == max_z) || (bx == max_x && bz == z + (radius - 1)) {
                        bits = 9;
                    }
                }

                if bits == 5 && by < top {
                    bits = 0;
                }

                if (y >= top - 1 || bits != 0) && !is_full(view.get(bx, by, bz)) {
                    view.set(bx, by, bz, cap(bits as u8));
                }
            }
        }
    }

    for i in 0..height {
        if !is_full(view.get(x, y + i, z)) {
            view.set(x, y + i, z, blocks::MUSHROOM_STEM);
        }
    }

    true
}
