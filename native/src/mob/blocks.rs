//! What mobs need to know about blocks: how high they stand, and whether they are water,
//! lava, something to climb or something that hurts. PHP works this out for a block state
//! the first time a mob comes across it, the table here remembers it.

use std::cell::{Cell, RefCell};
use std::collections::HashMap;
use std::sync::{Arc, RwLock};

use crate::chunk::{Chunk, MAX_Y, MIN_Y};

pub const HEIGHT: u16 = 0x00ff;
pub const WATER: u16 = 0x0100;
pub const LAVA: u16 = 0x0200;
pub const CLIMB: u16 = 0x0400;
pub const DAMAGE: u16 = 0x0800;
pub const GRASS_BLOCK: u16 = 0x1000;
pub const GRASS_PLANT: u16 = 0x2000;
pub const FIRE: u16 = 0x4000;

const FULL: u16 = 16;

static FLAGS: RwLock<Option<Arc<HashMap<u32, u16>>>> = RwLock::new(None);

pub fn table() -> Arc<HashMap<u32, u16>> {
    let guard = FLAGS.read().unwrap_or_else(|poisoned| poisoned.into_inner());
    guard.clone().unwrap_or_default()
}

pub fn learn(entries: impl Iterator<Item = (u32, u16)>) {
    let mut guard = FLAGS.write().unwrap_or_else(|poisoned| poisoned.into_inner());
    let mut table = guard.as_deref().cloned().unwrap_or_default();
    table.extend(entries);
    *guard = Some(Arc::new(table));
}

pub struct View {
    chunks: HashMap<(i32, i32), Chunk>,
    flags: Arc<HashMap<u32, u16>>,
    unknown: RefCell<Vec<u32>>,
    last: Cell<Option<(i32, i32, *const Chunk)>>,
}

unsafe impl Send for View {}

impl View {
    pub fn new(chunks: HashMap<(i32, i32), Chunk>, flags: Arc<HashMap<u32, u16>>) -> Self {
        View { chunks, flags, unknown: RefCell::new(Vec::new()), last: Cell::new(None) }
    }

    pub fn is_loaded(&self, x: i32, z: i32) -> bool {
        self.chunks.contains_key(&(x >> 4, z >> 4))
    }

    fn chunk(&self, chunk_x: i32, chunk_z: i32) -> Option<&Chunk> {
        if let Some((x, z, chunk)) = self.last.get() {
            if x == chunk_x && z == chunk_z {
                return Some(unsafe { &*chunk });
            }
        }

        let chunk = self.chunks.get(&(chunk_x, chunk_z))?;
        self.last.set(Some((chunk_x, chunk_z, chunk as *const Chunk)));
        Some(chunk)
    }

    fn lookup(&self, state: u32) -> u16 {
        match self.flags.get(&state) {
            Some(flags) => *flags,
            None => {
                let mut unknown = self.unknown.borrow_mut();

                if unknown.len() < 4096 && !unknown.contains(&state) {
                    unknown.push(state);
                }
                FULL
            }
        }
    }

    pub fn block(&self, x: i32, y: i32, z: i32) -> u16 {
        if !(MIN_Y..=MAX_Y).contains(&y) {
            return 0;
        }

        let Some(chunk) = self.chunk(x >> 4, z >> 4) else {
            return FULL;
        };

        let (lx, lz) = ((x & 15) as usize, (z & 15) as usize);
        let state = chunk.get_block(lx, y, lz, 0);
        let mut flags = if state == chunk.air { 0 } else { self.lookup(state) };

        let water = chunk.get_block(lx, y, lz, 1);

        if water != chunk.air {
            flags |= self.lookup(water) & WATER;
        }

        flags
    }

    pub fn take_unknown(&self) -> Vec<u32> {
        self.unknown.take()
    }
}

#[inline]
pub fn height(flags: u16) -> f64 {
    (flags & HEIGHT) as f64 / 16.0
}

#[inline]
pub fn is_solid(flags: u16) -> bool {
    flags & HEIGHT != 0
}