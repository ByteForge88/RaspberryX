//! Block light and sky light. The client works out its own light to draw the world with, the
//! server needs it to decide things: whether crops grow, where monsters spawn, when snow melts.
//!
//! Light spreads the way the game spreads it: a block gives off its light, and every step to a
//! neighbor takes one level away, or as much as the block it enters takes. Sky light starts at
//! 15 above everything and falls straight down without losing any until something stops it.

use std::cell::Cell;
use std::collections::{HashMap, VecDeque};
use std::sync::{Arc, RwLock};

use crate::chunk::{index, Chunk, MAX_Y, MIN_SUBCHUNK, MIN_Y, SIZE, SUBCHUNKS};

/// a block's light byte: the high four bits are the light it gives off, the low four how much
/// light it takes away from light passing through
pub type Props = u8;

/// what a block nobody described is: solid and dark, like stone
const SOLID: Props = 0x0f;

pub const MAX_LIGHT: u8 = 15;

static TABLE: RwLock<Option<Arc<HashMap<u32, Props>>>> = RwLock::new(None);

fn table() -> Arc<HashMap<u32, Props>> {
    let guard = TABLE.read().unwrap_or_else(|poisoned| poisoned.into_inner());
    guard.clone().unwrap_or_default()
}

pub fn learn(entries: impl Iterator<Item = (u32, Props)>) {
    let mut guard = TABLE.write().unwrap_or_else(|poisoned| poisoned.into_inner());
    let mut table = guard.as_deref().cloned().unwrap_or_default();
    table.extend(entries);
    *guard = Some(Arc::new(table));
}

#[inline]
fn emission(props: Props) -> u8 {
    props >> 4
}

#[inline]
fn filter(props: Props) -> u8 {
    props & 15
}

/// One value per block of a sub-chunk, kept as one number while they are all the same.
#[derive(Clone)]
enum Section {
    Uniform(u8),
    Data(Box<[u8; SIZE]>),
}

impl Section {
    #[inline]
    fn get(&self, index: usize) -> u8 {
        match self {
            Section::Uniform(value) => *value,
            Section::Data(data) => data[index],
        }
    }

    #[inline]
    fn set(&mut self, index: usize, value: u8) {
        match self {
            Section::Uniform(current) if *current == value => {}
            Section::Uniform(current) => {
                let mut data = Box::new([*current; SIZE]);
                data[index] = value;
                *self = Section::Data(data);
            }
            Section::Data(data) => data[index] = value,
        }
    }
}

#[derive(Clone, Copy, PartialEq)]
enum Kind {
    Block,
    Sky,
}

struct LightChunk {
    props: Vec<Section>,
    block: Vec<Section>,
    sky: Vec<Section>,
    /// for each column, the lowest Y that open sky reaches straight down
    sky_bottom: [i16; 256],
}

#[inline]
fn split(y: i32) -> (usize, usize) {
    let offset = (y - MIN_Y) as usize;
    (offset >> 4, offset & 15)
}

impl LightChunk {
    fn new(chunk: &Chunk, table: &HashMap<u32, Props>) -> Self {
        let describe = |state: u32| if state == chunk.air { 0 } else { *table.get(&state).unwrap_or(&SOLID) };
        let mut props = Vec::with_capacity(SUBCHUNKS);

        for subchunk in &chunk.subchunks {
            let main = &subchunk.layers[0];
            let water = subchunk.layers.get(1).filter(|layer| !(layer.is_uniform() && layer.palette[0] == chunk.air));

            if main.is_uniform() && water.is_none() {
                props.push(Section::Uniform(describe(main.palette[0])));
                continue;
            }

            let main_props: Vec<Props> = main.palette.iter().map(|state| describe(*state)).collect();
            let mut data = Box::new([0u8; SIZE]);

            for (index, offset) in main.offsets().enumerate() {
                data[index] = main_props[offset as usize];
            }

            //a waterlogged block lets through what both of them let through
            if let Some(water) = water {
                let water_props: Vec<Props> = water.palette.iter().map(|state| describe(*state)).collect();

                for (index, offset) in water.offsets().enumerate() {
                    let other = water_props[offset as usize];
                    let own = data[index];
                    data[index] = emission(own).max(emission(other)) << 4 | filter(own).max(filter(other));
                }
            }

            props.push(Section::Data(data));
        }

        LightChunk {
            props,
            block: vec![Section::Uniform(0); SUBCHUNKS],
            sky: vec![Section::Uniform(0); SUBCHUNKS],
            sky_bottom: [MIN_Y as i16; 256],
        }
    }

    #[inline]
    fn props(&self, x: usize, y: i32, z: usize) -> Props {
        let (sub, local) = split(y);
        self.props[sub].get(index(x, local, z))
    }

    #[inline]
    fn light(&self, kind: Kind, x: usize, y: i32, z: usize) -> u8 {
        let (sub, local) = split(y);
        let sections = if kind == Kind::Block { &self.block } else { &self.sky };
        sections[sub].get(index(x, local, z))
    }

    #[inline]
    fn set_light(&mut self, kind: Kind, x: usize, y: i32, z: usize, level: u8) {
        let (sub, local) = split(y);
        let sections = if kind == Kind::Block { &mut self.block } else { &mut self.sky };
        sections[sub].set(index(x, local, z), level);
    }

    /// Fills the open sky down every column, and notes where each one stops.
    fn fill_sky(&mut self) {
        //everything above the highest sub-chunk that holds anything is open sky
        let top = (0..SUBCHUNKS).rev().find(|sub| !matches!(self.props[*sub], Section::Uniform(props) if filter(props) == 0));

        let Some(top) = top else {
            self.sky = vec![Section::Uniform(MAX_LIGHT); SUBCHUNKS];
            return;
        };

        for sub in top + 1..SUBCHUNKS {
            self.sky[sub] = Section::Uniform(MAX_LIGHT);
        }

        let top_y = ((top as i32 + MIN_SUBCHUNK) << 4) + 15;

        for x in 0..16 {
            for z in 0..16 {
                let mut y = top_y;

                while y >= MIN_Y && filter(self.props(x, y, z)) == 0 {
                    self.set_light(Kind::Sky, x, y, z, MAX_LIGHT);
                    y -= 1;
                }

                self.sky_bottom[(x << 4) | z] = (y + 1) as i16;
            }
        }
    }
}

const DIRECTIONS: [(i32, i32, i32); 6] = [(1, 0, 0), (-1, 0, 0), (0, 0, 1), (0, 0, -1), (0, 1, 0), (0, -1, 0)];

/// The light of one world's loaded chunks.
#[derive(Default)]
pub struct LightWorld {
    //boxed, so a chunk stays where it is while the map grows
    chunks: HashMap<(i32, i32), Box<LightChunk>>,
    /// the chunk looked at last: light spreads block by block, mostly within one chunk
    last: Cell<Option<((i32, i32), *mut LightChunk)>>,
    increase: VecDeque<(i32, i32, i32)>,
    decrease: VecDeque<(i32, i32, i32, u8)>,
}

//the pointer in `last` only points into `chunks`, which moves with the world
unsafe impl Send for LightWorld {}

impl LightWorld {
    pub fn new() -> Self {
        LightWorld::default()
    }

    #[inline]
    fn chunk_ptr(&self, x: i32, z: i32) -> Option<*mut LightChunk> {
        let key = (x >> 4, z >> 4);

        if let Some((last, pointer)) = self.last.get() {
            if last == key {
                return Some(pointer);
            }
        }

        let pointer = self.chunks.get(&key).map(|chunk| &**chunk as *const LightChunk as *mut LightChunk)?;
        self.last.set(Some((key, pointer)));
        Some(pointer)
    }

    #[inline]
    fn chunk(&self, x: i32, z: i32) -> Option<&LightChunk> {
        self.chunk_ptr(x, z).map(|pointer| unsafe { &*pointer })
    }

    #[inline]
    fn chunk_mut(&mut self, x: i32, z: i32) -> Option<&mut LightChunk> {
        self.chunk_ptr(x, z).map(|pointer| unsafe { &mut *pointer })
    }

    /// What a block does to light, None where no chunk is loaded.
    #[inline]
    fn props(&self, x: i32, y: i32, z: i32) -> Option<Props> {
        if !(MIN_Y..=MAX_Y).contains(&y) {
            return None;
        }

        self.chunk(x, z).map(|chunk| chunk.props((x & 15) as usize, y, (z & 15) as usize))
    }

    #[inline]
    fn light(&self, kind: Kind, x: i32, y: i32, z: i32) -> u8 {
        if y > MAX_Y {
            return if kind == Kind::Sky { MAX_LIGHT } else { 0 };
        }

        if y < MIN_Y {
            return 0;
        }

        self.chunk(x, z).map_or(0, |chunk| chunk.light(kind, (x & 15) as usize, y, (z & 15) as usize))
    }

    #[inline]
    fn set_light(&mut self, kind: Kind, x: i32, y: i32, z: i32, level: u8) {
        if let Some(chunk) = self.chunk_mut(x, z) {
            chunk.set_light(kind, (x & 15) as usize, y, (z & 15) as usize, level);
        }
    }

    fn sky_bottom_at(&self, x: i32, z: i32) -> Option<i32> {
        self.chunk(x, z).map(|chunk| chunk.sky_bottom[(((x & 15) << 4) | (z & 15)) as usize] as i32)
    }

    /// The block light and the sky light at a position, None where no chunk is loaded.
    pub fn get(&self, x: i32, y: i32, z: i32) -> Option<(u8, u8)> {
        self.chunk(x, z)?;

        Some((self.light(Kind::Block, x, y, z), self.light(Kind::Sky, x, y, z)))
    }

    /// The lowest Y that open sky reaches in a column: a block there or above sees the sky.
    pub fn sky_bottom(&self, x: i32, z: i32) -> Option<i32> {
        self.sky_bottom_at(x, z)
    }

    /// Lights a chunk that just loaded, and lets light flow between it and its loaded neighbors.
    pub fn add_chunk(&mut self, chunk_x: i32, chunk_z: i32, chunk: &Chunk) {
        let mut light = Box::new(LightChunk::new(chunk, &table()));
        light.fill_sky();
        self.last.set(None);
        self.chunks.insert((chunk_x, chunk_z), light);

        let (base_x, base_z) = (chunk_x << 4, chunk_z << 4);

        //what gives off light
        if let Some(light) = self.chunks.get_mut(&(chunk_x, chunk_z)) {
            for sub in 0..SUBCHUNKS {
                let bottom = ((sub as i32 + MIN_SUBCHUNK) << 4) as i32;

                match &light.props[sub] {
                    Section::Uniform(props) if emission(*props) == 0 => continue,
                    _ => {}
                }

                for x in 0..16usize {
                    for z in 0..16usize {
                        for y in 0..16usize {
                            let emit = emission(light.props[sub].get(index(x, y, z)));

                            if emit > 0 {
                                light.set_light(Kind::Block, x, bottom + y as i32, z, emit);
                                self.increase.push_back((base_x + x as i32, bottom + y as i32, base_z + z as i32));
                            }
                        }
                    }
                }
            }
        }

        self.spread(Kind::Block);

        //sky light runs sideways from each column into lower neighbors, and down into water and leaves
        for x in 0..16 {
            for z in 0..16 {
                let (world_x, world_z) = (base_x + x, base_z + z);
                let Some(bottom) = self.sky_bottom_at(world_x, world_z) else { continue };
                let mut reach = bottom;

                for (dx, _, dz) in &DIRECTIONS[..4] {
                    if let Some(other) = self.sky_bottom_at(world_x + dx, world_z + dz) {
                        reach = reach.max(other);
                    }
                }

                for y in bottom..=reach.min(MAX_Y) {
                    self.increase.push_back((world_x, y, world_z));
                }
            }
        }

        self.spread(Kind::Sky);

        //light of the neighbors that can come in now
        for kind in [Kind::Block, Kind::Sky] {
            for (dx, dz) in [(-1, 0), (16, 0), (0, -1), (0, 16)] {
                for along in 0..16 {
                    let (x, z) = if dx != 0 { (base_x + dx, base_z + along) } else { (base_x + along, base_z + dz) };

                    if self.chunk(x, z).is_none() {
                        break;
                    }

                    for y in MIN_Y..=MAX_Y {
                        if self.light(kind, x, y, z) > 1 {
                            self.increase.push_back((x, y, z));
                        }
                    }
                }
            }

            self.spread(kind);
        }
    }

    pub fn remove_chunk(&mut self, chunk_x: i32, chunk_z: i32) {
        self.last.set(None);
        self.chunks.remove(&(chunk_x, chunk_z));
    }

    /// A block changed: takes away the light it no longer gives off or lets through, and lets
    /// in what can now come in. `props` is what the block now does to light.
    pub fn update(&mut self, x: i32, y: i32, z: i32, props: Props) {
        if !(MIN_Y..=MAX_Y).contains(&y) {
            return;
        }

        let Some(chunk) = self.chunk_mut(x, z) else { return };
        let (lx, lz) = ((x & 15) as usize, (z & 15) as usize);
        let (sub, local) = split(y);
        let old = chunk.props[sub].get(index(lx, local, lz));

        if old == props {
            return;
        }

        chunk.props[sub].set(index(lx, local, lz), props);

        //block light: all of it goes, then it comes back from what is still lit
        let level = self.light(Kind::Block, x, y, z);

        if level > 0 {
            self.set_light(Kind::Block, x, y, z, 0);
            self.decrease.push_back((x, y, z, level));
        }

        self.darken(Kind::Block);

        if emission(props) > 0 {
            self.set_light(Kind::Block, x, y, z, emission(props));
            self.increase.push_back((x, y, z));
        }

        self.relight_around(Kind::Block, x, y, z);
        self.spread(Kind::Block);

        //sky light: a column of open sky can get cut, or reach further down
        let bottom = self.sky_bottom_at(x, z).unwrap_or(MIN_Y);
        let column = (((x & 15) << 4) | (z & 15)) as usize;

        if filter(props) > 0 && y >= bottom {
            for cy in bottom..=y {
                let level = self.light(Kind::Sky, x, cy, z);
                self.set_light(Kind::Sky, x, cy, z, 0);
                self.decrease.push_back((x, cy, z, level));
            }

            self.set_bottom(x, z, column, y + 1);
            self.darken(Kind::Sky);
        } else if filter(props) == 0 && y == bottom - 1 {
            let mut cy = y;

            while cy >= MIN_Y && self.props(x, cy, z).is_some_and(|props| filter(props) == 0) {
                self.set_light(Kind::Sky, x, cy, z, MAX_LIGHT);
                self.increase.push_back((x, cy, z));
                cy -= 1;
            }

            self.set_bottom(x, z, column, cy + 1);
        } else if y < bottom {
            let level = self.light(Kind::Sky, x, y, z);

            if level > 0 {
                self.set_light(Kind::Sky, x, y, z, 0);
                self.decrease.push_back((x, y, z, level));
            }

            self.darken(Kind::Sky);
        }

        self.relight_around(Kind::Sky, x, y, z);
        self.spread(Kind::Sky);
    }

    fn set_bottom(&mut self, x: i32, z: i32, column: usize, bottom: i32) {
        if let Some(chunk) = self.chunk_mut(x, z) {
            chunk.sky_bottom[column] = bottom as i16;
        }
    }

    /// The neighbors spread into a block again, for when it lets more light through now.
    fn relight_around(&mut self, kind: Kind, x: i32, y: i32, z: i32) {
        for (dx, dy, dz) in DIRECTIONS {
            if self.light(kind, x + dx, y + dy, z + dz) > 1 {
                self.increase.push_back((x + dx, y + dy, z + dz));
            }
        }
    }

    /// Takes away the light that came from the blocks in the decrease queue. Light that came from
    /// somewhere else is queued to flow back in.
    fn darken(&mut self, kind: Kind) {
        while let Some((x, y, z, old)) = self.decrease.pop_front() {
            for (dx, dy, dz) in DIRECTIONS {
                let (nx, ny, nz) = (x + dx, y + dy, z + dz);
                let Some(props) = self.props(nx, ny, nz) else { continue };
                let level = self.light(kind, nx, ny, nz);

                if level == 0 {
                    continue;
                }

                //open sky lights itself, it never came from the block that went dark
                let open_sky = kind == Kind::Sky && self.sky_bottom_at(nx, nz).is_some_and(|bottom| ny >= bottom);

                if level < old && !open_sky {
                    self.set_light(kind, nx, ny, nz, 0);
                    self.decrease.push_back((nx, ny, nz, level));

                    if kind == Kind::Block && emission(props) > 0 {
                        self.set_light(kind, nx, ny, nz, emission(props));
                        self.increase.push_back((nx, ny, nz));
                    }
                } else {
                    self.increase.push_back((nx, ny, nz));
                }
            }
        }
    }

    /// Spreads the light of the blocks in the increase queue as far as it goes.
    fn spread(&mut self, kind: Kind) {
        while let Some((x, y, z)) = self.increase.pop_front() {
            let level = self.light(kind, x, y, z);

            if level <= 1 {
                continue;
            }

            for (dx, dy, dz) in DIRECTIONS {
                let (nx, ny, nz) = (x + dx, y + dy, z + dz);
                let Some(props) = self.props(nx, ny, nz) else { continue };
                let taken = filter(props);

                //open sky falls straight through what lets everything through
                let lost = if kind == Kind::Sky && dy == -1 && level == MAX_LIGHT && taken == 0 { 0 } else { taken.max(1) };

                if level <= lost {
                    continue;
                }

                let reached = level - lost;

                if reached > self.light(kind, nx, ny, nz) {
                    self.set_light(kind, nx, ny, nz, reached);
                    self.increase.push_back((nx, ny, nz));
                }
            }
        }
    }
}

/// What a block in a chunk does to light, from both of its layers.
pub fn props_at(chunk: &Chunk, x: usize, y: i32, z: usize) -> Props {
    let table = table();
    let describe = |state: u32| if state == chunk.air { 0 } else { *table.get(&state).unwrap_or(&SOLID) };
    let main = describe(chunk.get_block(x, y, z, 0));
    let water = describe(chunk.get_block(x, y, z, 1));

    emission(main).max(emission(water)) << 4 | filter(main).max(filter(water))
}

#[cfg(test)]
mod tests {
    use super::*;

    const AIR: u32 = 0;
    const STONE: u32 = 1;
    const TORCH: u32 = 2;
    const GLASS: u32 = 3;
    const WATER: u32 = 4;
    const GLOWSTONE: u32 = 5;
    const LEAVES: u32 = 6;

    fn teach() {
        learn([(STONE, 0x0f), (TORCH, 0xe0), (GLASS, 0x00), (WATER, 0x01), (GLOWSTONE, 0xff), (LEAVES, 0x01)].into_iter());
    }

    /// stone up to y 63 in a square of chunks around the origin
    fn world(radius: i32, build: impl Fn(i32, i32, &mut Chunk)) -> (LightWorld, HashMap<(i32, i32), Chunk>) {
        teach();
        let mut chunks = HashMap::new();

        for cx in -radius..=radius {
            for cz in -radius..=radius {
                let mut chunk = Chunk::new(AIR, 1);
                chunk.fill(MIN_Y, 63, 0, STONE);
                build(cx, cz, &mut chunk);
                chunks.insert((cx, cz), chunk);
            }
        }

        let mut world = LightWorld::new();

        for ((cx, cz), chunk) in &chunks {
            world.add_chunk(*cx, *cz, chunk);
        }

        (world, chunks)
    }

    fn set(world: &mut LightWorld, chunks: &mut HashMap<(i32, i32), Chunk>, x: i32, y: i32, z: i32, state: u32) {
        let chunk = chunks.get_mut(&(x >> 4, z >> 4)).unwrap();
        chunk.set_block((x & 15) as usize, y, (z & 15) as usize, 0, state);
        let props = props_at(chunk, (x & 15) as usize, y, (z & 15) as usize);
        world.update(x, y, z, props);
    }

    #[test]
    fn open_sky_is_full_and_ground_is_dark() {
        let (world, _) = world(1, |_, _, _| {});

        assert_eq!(world.get(5, 64, 5), Some((0, 15)));
        assert_eq!(world.get(5, 200, 5), Some((0, 15)));
        assert_eq!(world.get(5, 63, 5), Some((0, 0)));
        assert_eq!(world.sky_bottom(5, 5), Some(64));
        assert_eq!(world.get(100, 64, 100), None);
    }

    #[test]
    fn a_torch_lights_its_surroundings() {
        let (mut world, mut chunks) = world(1, |_, _, _| {});
        set(&mut world, &mut chunks, 3, 64, 3, TORCH);

        assert_eq!(world.get(3, 64, 3).unwrap().0, 14);
        assert_eq!(world.get(4, 64, 3).unwrap().0, 13);
        assert_eq!(world.get(3, 70, 3).unwrap().0, 8);
        assert_eq!(world.get(-5, 64, 3).unwrap().0, 6, "light crosses into the next chunk");
        assert_eq!(world.get(3, 63, 3).unwrap().0, 0, "stone stays dark");

        set(&mut world, &mut chunks, 3, 64, 3, AIR);

        assert_eq!(world.get(3, 64, 3).unwrap().0, 0);
        assert_eq!(world.get(-5, 64, 3).unwrap().0, 0);
    }

    #[test]
    fn two_torches_and_one_goes_out() {
        let (mut world, mut chunks) = world(1, |_, _, _| {});
        set(&mut world, &mut chunks, 0, 64, 0, TORCH);
        set(&mut world, &mut chunks, 6, 64, 0, TORCH);

        assert_eq!(world.get(3, 64, 0).unwrap().0, 11);

        set(&mut world, &mut chunks, 0, 64, 0, AIR);

        assert_eq!(world.get(3, 64, 0).unwrap().0, 11, "the other torch still reaches");
        assert_eq!(world.get(0, 64, 0).unwrap().0, 8);
    }

    #[test]
    fn a_roof_casts_shade_and_light_comes_in_from_the_side() {
        let (mut world, mut chunks) = world(1, |_, _, _| {});

        for x in 0..5 {
            for z in 0..5 {
                set(&mut world, &mut chunks, x, 66, z, STONE);
            }
        }

        assert_eq!(world.sky_bottom(2, 2), Some(67));
        assert_eq!(world.get(2, 64, 2).unwrap().1, 12, "three steps in from the open side");
        assert_eq!(world.get(4, 64, 4).unwrap().1, 14);

        set(&mut world, &mut chunks, 2, 66, 2, AIR);

        assert_eq!(world.sky_bottom(2, 2), Some(64));
        assert_eq!(world.get(2, 64, 2).unwrap().1, 15);
    }

    #[test]
    fn a_sealed_room_is_dark() {
        let (mut world, mut chunks) = world(1, |_, _, _| {});

        for x in 0..5 {
            for z in 0..5 {
                for y in 64..=67 {
                    let wall = x == 0 || x == 4 || z == 0 || z == 4 || y == 67;
                    set(&mut world, &mut chunks, x, y, z, if wall { STONE } else { AIR });
                }
            }
        }

        assert_eq!(world.get(2, 65, 2), Some((0, 0)));

        set(&mut world, &mut chunks, 2, 64, 2, GLOWSTONE);

        assert_eq!(world.get(2, 65, 2).unwrap().0, 14);
        assert_eq!(world.get(6, 65, 2).unwrap().0, 0, "the walls keep it in");
    }

    #[test]
    fn water_and_leaves_dim_the_sky_and_glass_does_not() {
        let (world, _) = world(1, |cx, cz, chunk| {
            if (cx, cz) == (0, 0) {
                chunk.fill(60, 63, 0, WATER);
                chunk.set_block(8, 70, 8, 0, GLASS);
                chunk.set_block(3, 70, 3, 0, LEAVES);
            }
        });

        assert_eq!(world.get(8, 60, 8).unwrap().1, 11, "four blocks of water, one level each");
        assert_eq!(world.get(8, 69, 8).unwrap().1, 15);
        assert_eq!(world.get(3, 69, 3).unwrap().1, 14);
        assert_eq!(world.sky_bottom(8, 8), Some(64));
    }

    #[test]
    fn a_chunk_that_loads_later_gets_its_neighbors_light() {
        teach();
        let mut lit = Chunk::new(AIR, 1);
        lit.fill(MIN_Y, 63, 0, STONE);
        lit.set_block(15, 64, 8, 0, TORCH);
        let mut dark = Chunk::new(AIR, 1);
        dark.fill(MIN_Y, 63, 0, STONE);

        let mut world = LightWorld::new();
        world.add_chunk(0, 0, &lit);
        world.add_chunk(1, 0, &dark);

        assert_eq!(world.get(16, 64, 8).unwrap().0, 13);
        assert_eq!(world.get(20, 64, 8).unwrap().0, 9);
    }

    #[test]
    #[ignore]
    fn how_long_lighting_takes() {
        teach();
        let mut chunk = Chunk::new(AIR, 1);
        chunk.fill(MIN_Y, 63, 0, STONE);

        for x in (0..16).step_by(5) {
            for z in (0..16).step_by(5) {
                chunk.set_block(x, 64, z, 0, TORCH);
                chunk.set_block(x, 40, z, 0, AIR);
                chunk.set_block(x, 66 + (x as i32 % 3), z, 0, STONE);
            }
        }

        let mut world = LightWorld::new();
        let start = std::time::Instant::now();

        for cx in -10..10 {
            for cz in -10..10 {
                world.add_chunk(cx, cz, &chunk);
            }
        }

        println!("400 chunks: {:?} each", start.elapsed() / 400);
    }
}