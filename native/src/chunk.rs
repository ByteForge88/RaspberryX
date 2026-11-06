//! Chunk storage. A chunk is 24 sub-chunks of 16 x 16 x 16 from Y -64 to 319, each with one or
//! two block layers (the second holds water in waterlogged blocks) and a biome per block.
//!
//! Blocks are stored as their state ID, the same FNV-1a hash of the state that the client uses
//! as its network ID, so nothing needs translating to send a chunk.
//!
//! Sub-chunks are shared through Arc: a snapshot for a worker thread costs a few pointer copies,
//! and the first write after it copies only the sub-chunk that changes.

use std::sync::Arc;

pub const MIN_Y: i32 = -64;
pub const MAX_Y: i32 = 319;
pub const SUBCHUNKS: usize = 24;
pub const MIN_SUBCHUNK: i32 = MIN_Y >> 4;
pub const MAX_LAYERS: usize = 2;
pub const SIZE: usize = 4096;

/// 4096 values as a palette plus one u16 palette offset per position, in Bedrock's XZY order.
/// A storage holding one value has no offsets at all.
#[derive(Clone, Debug)]
pub struct Storage {
    pub palette: Vec<u32>,
    pub indices: Option<Box<[u16]>>,
}

#[inline]
pub fn index(x: usize, y: usize, z: usize) -> usize {
    (x << 8) | (z << 4) | y
}

impl Storage {
    pub fn new(fill: u32) -> Self {
        Storage { palette: vec![fill], indices: None }
    }

    #[inline]
    pub fn get(&self, index: usize) -> u32 {
        match &self.indices {
            Some(indices) => self.palette[indices[index] as usize],
            None => self.palette[0],
        }
    }

    pub fn set(&mut self, index: usize, value: u32) {
        let offset = match self.palette.iter().position(|entry| *entry == value) {
            Some(offset) => offset,
            None => {
                if self.palette.len() >= SIZE {
                    self.collect_garbage();
                }

                self.palette.push(value);
                self.palette.len() - 1
            }
        };

        if self.indices.is_none() {
            if offset == 0 {
                return;
            }

            self.indices = Some(vec![0u16; SIZE].into_boxed_slice());
        }

        self.indices.as_mut().unwrap()[index] = offset as u16;
    }

    pub fn fill(&mut self, value: u32) {
        self.palette.clear();
        self.palette.push(value);
        self.indices = None;
    }

    pub fn is_uniform(&self) -> bool {
        self.indices.is_none()
    }

    /// Drops palette entries nothing uses, in the order they are first used, like the PHP
    /// storage did, so saved files stay the same.
    pub fn collect_garbage(&mut self) {
        let Some(indices) = &mut self.indices else {
            self.palette.truncate(1);
            return;
        };

        let mut remap = vec![u16::MAX; self.palette.len()];
        let mut palette = Vec::with_capacity(self.palette.len().min(16));

        for offset in indices.iter_mut() {
            let old = *offset as usize;

            if remap[old] == u16::MAX {
                remap[old] = palette.len() as u16;
                palette.push(self.palette[old]);
            }

            *offset = remap[old];
        }

        self.palette = palette;

        if self.palette.len() == 1 {
            self.indices = None;
        }
    }

    /// The palette offset of every position.
    pub fn offsets(&self) -> impl Iterator<Item = u16> + '_ {
        let indices = self.indices.as_deref();
        (0..SIZE).map(move |position| indices.map_or(0, |indices| indices[position]))
    }
}

#[derive(Clone, Debug)]
pub struct SubChunk {
    pub layers: Vec<Storage>,
    pub biomes: Storage,
}

impl SubChunk {
    pub fn new(air: u32, biome: u32) -> Self {
        SubChunk { layers: vec![Storage::new(air)], biomes: Storage::new(biome) }
    }

    pub fn is_empty(&self, air: u32) -> bool {
        self.layers.iter().all(|layer| layer.is_uniform() && layer.palette[0] == air)
    }

    pub fn collect_garbage(&mut self, air: u32) {
        for layer in &mut self.layers {
            layer.collect_garbage();
        }

        while self.layers.len() > 1 && self.layers.last().is_some_and(|layer| layer.is_uniform() && layer.palette[0] == air) {
            self.layers.pop();
        }

        self.biomes.collect_garbage();
    }
}

#[derive(Clone, Debug)]
pub struct Chunk {
    pub air: u32,
    pub subchunks: Vec<Arc<SubChunk>>,
    /// bumped on every change, a snapshot remembers which one it saw
    pub version: u64,
}

impl Chunk {
    pub fn new(air: u32, biome: u32) -> Self {
        let empty = Arc::new(SubChunk::new(air, biome));
        Chunk { air, subchunks: vec![empty; SUBCHUNKS], version: 0 }
    }

    /// Splits a world Y into the sub-chunk and the Y inside it, None when outside the world.
    #[inline]
    fn locate(y: i32) -> Option<(usize, usize)> {
        if !(MIN_Y..=MAX_Y).contains(&y) {
            return None;
        }

        Some((((y >> 4) - MIN_SUBCHUNK) as usize, (y & 15) as usize))
    }

    pub fn get_block(&self, x: usize, y: i32, z: usize, layer: usize) -> u32 {
        match Self::locate(y) {
            Some((sub, local)) if x < 16 && z < 16 => self.subchunks[sub].layers.get(layer).map_or(self.air, |storage| storage.get(index(x, local, z))),
            _ => self.air,
        }
    }

    pub fn set_block(&mut self, x: usize, y: i32, z: usize, layer: usize, state: u32) -> bool {
        let Some((sub, local)) = Self::locate(y) else { return false };

        if x >= 16 || z >= 16 || layer >= MAX_LAYERS {
            return false;
        }

        let air = self.air;

        //writing air to a layer that does not exist changes nothing
        if self.subchunks[sub].layers.len() <= layer && state == air {
            return true;
        }

        let subchunk = Arc::make_mut(&mut self.subchunks[sub]);

        while subchunk.layers.len() <= layer {
            subchunk.layers.push(Storage::new(air));
        }

        subchunk.layers[layer].set(index(x, local, z), state);
        self.version += 1;

        true
    }

    pub fn get_biome(&self, x: usize, y: i32, z: usize) -> u32 {
        match Self::locate(y) {
            Some((sub, local)) if x < 16 && z < 16 => self.subchunks[sub].biomes.get(index(x, local, z)),
            _ => 0,
        }
    }

    pub fn set_biome(&mut self, x: usize, y: i32, z: usize, biome: u32) -> bool {
        let Some((sub, local)) = Self::locate(y) else { return false };

        if x >= 16 || z >= 16 {
            return false;
        }

        Arc::make_mut(&mut self.subchunks[sub]).biomes.set(index(x, local, z), biome);
        self.version += 1;

        true
    }

    /// Sets every block from `min_y` to `max_y` in the whole chunk, quicker than one by one.
    pub fn fill(&mut self, min_y: i32, max_y: i32, layer: usize, state: u32) {
        let (min_y, max_y) = (min_y.max(MIN_Y), max_y.min(MAX_Y));

        if layer >= MAX_LAYERS || min_y > max_y {
            return;
        }

        let air = self.air;

        for sub in ((min_y >> 4) - MIN_SUBCHUNK) as usize..=((max_y >> 4) - MIN_SUBCHUNK) as usize {
            let bottom = (sub as i32 + MIN_SUBCHUNK) << 4;
            let (from, to) = ((min_y - bottom).max(0) as usize, (max_y - bottom).min(15) as usize);
            let subchunk = Arc::make_mut(&mut self.subchunks[sub]);

            while subchunk.layers.len() <= layer {
                subchunk.layers.push(Storage::new(air));
            }

            let storage = &mut subchunk.layers[layer];

            if from == 0 && to == 15 {
                storage.fill(state);
                continue;
            }

            for x in 0..16 {
                for z in 0..16 {
                    for y in from..=to {
                        storage.set(index(x, y, z), state);
                    }
                }
            }
        }

        self.version += 1;
    }

    pub fn fill_biome(&mut self, biome: u32) {
        for subchunk in &mut self.subchunks {
            Arc::make_mut(subchunk).biomes.fill(biome);
        }

        self.version += 1;
    }

    /// The highest sub-chunk index (0 is Y -64) that holds blocks, None when all is air.
    pub fn highest_subchunk(&self) -> Option<usize> {
        (0..SUBCHUNKS).rev().find(|sub| !self.subchunks[*sub].is_empty(self.air))
    }

    pub fn collect_garbage(&mut self) {
        let air = self.air;

        for subchunk in &mut self.subchunks {
            //only sub-chunks that might have something to drop are copied
            if subchunk.layers.len() > 1 || subchunk.layers.iter().chain(std::iter::once(&subchunk.biomes)).any(|storage| !storage.is_uniform()) {
                Arc::make_mut(subchunk).collect_garbage(air);
            }
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    const AIR: u32 = 1;
    const STONE: u32 = 2;
    const WATER: u32 = 3;

    #[test]
    fn blocks_and_layers() {
        let mut chunk = Chunk::new(AIR, 1);
        assert_eq!(chunk.get_block(3, 64, 5, 0), AIR);
        assert!(chunk.set_block(3, 64, 5, 0, STONE));
        assert!(chunk.set_block(3, 64, 5, 1, WATER));
        assert_eq!(chunk.get_block(3, 64, 5, 0), STONE);
        assert_eq!(chunk.get_block(3, 64, 5, 1), WATER);
        assert_eq!(chunk.get_block(3, 65, 5, 1), AIR);
        assert!(!chunk.set_block(3, 320, 5, 0, STONE));
        assert_eq!(chunk.highest_subchunk(), Some(8));

        chunk.set_block(3, 64, 5, 1, AIR);
        chunk.collect_garbage();
        assert_eq!(chunk.subchunks[8].layers.len(), 1);

        chunk.set_block(3, 64, 5, 0, AIR);
        chunk.collect_garbage();
        assert!(chunk.subchunks[8].layers[0].is_uniform());
        assert_eq!(chunk.highest_subchunk(), None);
    }

    #[test]
    fn snapshots_do_not_see_later_writes() {
        let mut chunk = Chunk::new(AIR, 1);
        chunk.set_block(0, 0, 0, 0, STONE);
        let snapshot = chunk.clone();
        chunk.set_block(0, 0, 0, 0, WATER);
        assert_eq!(snapshot.get_block(0, 0, 0, 0), STONE);
        assert_eq!(chunk.get_block(0, 0, 0, 0), WATER);
        //untouched sub-chunks are still shared
        assert!(Arc::ptr_eq(&snapshot.subchunks[0], &chunk.subchunks[0]));
    }

    #[test]
    fn fill_matches_single_sets() {
        let mut filled = Chunk::new(AIR, 1);
        let mut single = Chunk::new(AIR, 1);
        filled.fill(-64, -61, 0, STONE);
        filled.fill(-60, -60, 0, WATER);

        for x in 0..16 {
            for z in 0..16 {
                for y in -64..=-61 {
                    single.set_block(x, y, z, 0, STONE);
                }

                single.set_block(x, -60, z, 0, WATER);
            }
        }

        for y in -64..=-50 {
            assert_eq!(filled.get_block(7, y, 9, 0), single.get_block(7, y, 9, 0), "y {y}");
        }
    }
}