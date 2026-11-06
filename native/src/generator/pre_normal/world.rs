//! What decoration sees: the terrain of a chunk and the eight around it, with the writes of
//! the one decorator that is running on top. Each decorator reads the bare terrain and its
//! own work only, so a tree crossing into the next chunk comes out the same when that chunk
//! is made, whatever order chunks are made in.

use super::biomes::{self, LegacyBiome};
use super::terrain::{Terrain, HEIGHT};
use crate::generator::blocks::{self, Block};

pub const SIDE: i32 = 48;
const UNSET: Block = 0xff;

/// The terrain of 3 x 3 chunks, the middle one being made.
pub struct Region {
    /// world X and Z of the corner block
    pub origin_x: i32,
    pub origin_z: i32,
    pub base: Vec<Block>,
    pub biomes: Vec<u8>,
    /// one above the highest block that is not air, per column
    tops: Vec<i16>,
}

#[inline]
fn index(lx: i32, y: i32, lz: i32) -> usize {
    ((lx * SIDE + lz) * HEIGHT + y) as usize
}

impl Region {
    /// `terrains` in x-major order, from the chunk at -1, -1 of the middle one.
    pub fn new(chunk_x: i32, chunk_z: i32, terrains: &[&Terrain]) -> Self {
        let mut base = vec![blocks::AIR; (SIDE * SIDE * HEIGHT) as usize];
        let mut biomes = vec![0u8; (SIDE * SIDE) as usize];
        let mut tops = vec![0i16; (SIDE * SIDE) as usize];

        for (number, terrain) in terrains.iter().enumerate() {
            let (cx, cz) = ((number / 3) as i32, (number % 3) as i32);

            for x in 0..16 {
                for z in 0..16 {
                    let (lx, lz) = (cx * 16 + x, cz * 16 + z);
                    let from = super::terrain::index(x, 0, z);
                    let to = index(lx, 0, lz);
                    let column = &terrain.blocks[from..from + HEIGHT as usize];
                    base[to..to + HEIGHT as usize].copy_from_slice(column);
                    biomes[(lx * SIDE + lz) as usize] = terrain.biomes[(x + z * 16) as usize];
                    tops[(lx * SIDE + lz) as usize] = column.iter().rposition(|&block| block != blocks::AIR).map_or(0, |y| y as i16 + 1);
                }
            }
        }

        Region { origin_x: (chunk_x - 1) * 16, origin_z: (chunk_z - 1) * 16, base, biomes, tops }
    }

    pub fn local(&self, x: i32, z: i32) -> Option<(i32, i32)> {
        let (lx, lz) = (x - self.origin_x, z - self.origin_z);
        ((0..SIDE).contains(&lx) && (0..SIDE).contains(&lz)).then_some((lx, lz))
    }

    pub fn biome(&self, x: i32, z: i32) -> &'static LegacyBiome {
        match self.local(x, z) {
            Some((lx, lz)) => biomes::of(self.biomes[(lx * SIDE + lz) as usize] as i32),
            None => biomes::of(biomes::id::PLAINS),
        }
    }
}

/// The blocks after every decorator ran, for the middle chunk.
pub struct Merged {
    pub blocks: Vec<Block>,
}

impl Merged {
    pub fn new(region: &Region) -> Self {
        Merged { blocks: region.base.clone() }
    }

    pub fn get(&self, region: &Region, x: i32, y: i32, z: i32) -> Block {
        match region.local(x, z) {
            Some((lx, lz)) if (0..HEIGHT).contains(&y) => self.blocks[index(lx, y, lz)],
            _ => blocks::AIR,
        }
    }

    pub fn set(&mut self, region: &Region, x: i32, y: i32, z: i32, block: Block) {
        if let Some((lx, lz)) = region.local(x, z) {
            if (0..HEIGHT).contains(&y) {
                self.blocks[index(lx, y, lz)] = block;
            }
        }
    }
}

/// One decorator's view: the bare terrain with its own writes over it.
pub struct View<'a> {
    pub region: &'a Region,
    overlay: Vec<Block>,
    touched: Vec<u32>,
    highest: i32,
}

impl<'a> View<'a> {
    pub fn new(region: &'a Region) -> Self {
        View { region, overlay: vec![UNSET; region.base.len()], touched: Vec::new(), highest: 0 }
    }

    pub fn get(&self, x: i32, y: i32, z: i32) -> Block {
        if !(0..HEIGHT).contains(&y) {
            return blocks::AIR;
        }

        match self.region.local(x, z) {
            Some((lx, lz)) => {
                let i = index(lx, y, lz);
                let over = self.overlay[i];
                if over == UNSET { self.region.base[i] } else { over }
            }
            None => blocks::AIR,
        }
    }

    pub fn set(&mut self, x: i32, y: i32, z: i32, block: Block) {
        if !(0..HEIGHT).contains(&y) {
            return;
        }

        if let Some((lx, lz)) = self.region.local(x, z) {
            let i = index(lx, y, lz);

            if self.overlay[i] == UNSET {
                self.touched.push(i as u32);
            }

            self.overlay[i] = block;
            self.highest = self.highest.max(y + 1);
        }
    }

    pub fn is_air(&self, x: i32, y: i32, z: i32) -> bool {
        self.get(x, y, z) == blocks::AIR
    }

    pub fn biome(&self, x: i32, z: i32) -> &'static LegacyBiome {
        self.region.biome(x, z)
    }

    fn scan_from(&self, x: i32, z: i32) -> i32 {
        match self.region.local(x, z) {
            Some((lx, lz)) => (self.region.tops[(lx * SIDE + lz) as usize] as i32).max(self.highest).min(HEIGHT),
            None => 0,
        }
    }

    /// The game's height map: one above the highest block that holds back light.
    pub fn height(&self, x: i32, z: i32) -> i32 {
        let mut y = self.scan_from(x, z) - 1;

        while y >= 0 && !blocks_light(self.get(x, y, z)) {
            y -= 1;
        }

        y + 1
    }

    /// One above the highest block that stops movement, through leaves; that is in the water
    /// over a sea floor.
    pub fn top_solid_or_liquid(&self, x: i32, z: i32) -> i32 {
        let mut y = self.scan_from(x, z) - 1;

        while y >= 0 {
            let block = self.get(x, y, z);

            if blocks_movement(block) && !blocks::is_leaves(block) {
                break;
            }

            y -= 1;
        }

        y + 1
    }

    /// Whether the sun reaches the spot well enough for flowers and grass: no full block
    /// above it.
    pub fn is_lit(&self, x: i32, y: i32, z: i32) -> bool {
        let top = self.scan_from(x, z);
        (y + 1..top).all(|above| !is_full(self.get(x, above, z)))
    }

    /// Whether nothing at all is over the spot, leaves included. Mushrooms want the shade.
    pub fn is_open(&self, x: i32, y: i32, z: i32) -> bool {
        let top = self.scan_from(x, z);
        (y + 1..top).all(|above| !blocks_light(self.get(x, above, z)) || is_liquid(self.get(x, above, z)))
    }

    /// Hands this decorator's writes to the merged blocks, then forgets them. A block another
    /// decorator already changed stays, except leaves under a new log.
    pub fn merge_into(&mut self, merged: &mut Merged) {
        for &i in &self.touched {
            let i = i as usize;
            let new = self.overlay[i];
            let current = merged.blocks[i];

            if current == self.region.base[i] || (blocks::is_log(new) && blocks::is_leaves(current)) {
                merged.blocks[i] = new;
            }

            self.overlay[i] = UNSET;
        }

        self.touched.clear();
        self.highest = 0;
    }
}

/// Plants, vines and the like: no material of their own to speak of.
pub fn is_thin(block: Block) -> bool {
    blocks::is_plant(block) || blocks::is_vine(block) || matches!(block, blocks::SNOW_LAYER | blocks::LILY_PAD) || blocks::is_cocoa(block)
}

pub fn is_liquid(block: Block) -> bool {
    matches!(block, blocks::WATER | blocks::LAVA)
}

/// The game's Material.isSolid.
pub fn is_solid(block: Block) -> bool {
    block != blocks::AIR && !is_liquid(block) && !is_thin(block)
}

pub fn blocks_movement(block: Block) -> bool {
    is_solid(block)
}

/// A full, opaque cube.
pub fn is_full(block: Block) -> bool {
    is_solid(block) && !blocks::is_leaves(block) && !matches!(block, blocks::ICE | blocks::CACTUS)
}

pub fn blocks_light(block: Block) -> bool {
    block != blocks::AIR && !is_thin(block) && block != blocks::CACTUS
}

/// Dirt the way the game counts it: coarse dirt and podzol are dirt too.
pub fn is_dirt(block: Block) -> bool {
    matches!(block, blocks::DIRT | blocks::COARSE_DIRT | blocks::PODZOL)
}

pub fn is_grass_or_dirt(block: Block) -> bool {
    block == blocks::GRASS_BLOCK || is_dirt(block)
}
