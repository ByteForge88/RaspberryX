//! Water and lava flow, done the way the game does it: a liquid block gets a tick some time
//! after something next to it changed, works out how full it should be from its neighbors,
//! and spreads down first, then towards the nearest drop within a few blocks.
//!
//! Nothing is written here. A tick hands back what changes and PHP sets the blocks, so the
//! light, the players and redstone hear about it like any other change.

use std::cmp::Reverse;
use std::collections::{BinaryHeap, HashMap, HashSet};
use std::sync::{Arc, RwLock};

use crate::chunk::{Chunk, MAX_Y, MIN_SUBCHUNK, MIN_Y};

pub const EMPTY: u8 = 0;
pub const FLOODABLE: u8 = 1;
pub const SOLID: u8 = 2;
pub const WATER: u8 = 3;
pub const FLOWING_WATER: u8 = 4;
pub const LAVA: u8 = 5;
pub const FLOWING_LAVA: u8 = 6;
pub const WATERY: u8 = 7;
pub const BUBBLE_COLUMN: u8 = 8;
pub const CONCRETE_POWDER: u8 = 9;
pub const SOUL_SAND: u8 = 10;
pub const MAGMA: u8 = 11;
pub const SOUL_SOIL: u8 = 12;
pub const BLUE_ICE: u8 = 13;

pub const DROPS: u8 = 1;

pub const FIZZ: u8 = 2;

const WATER_DELAY: u64 = 5;
const LAVA_DELAY: u64 = 30;
const POWDER_DELAY: u64 = 1;

const WATER_SLOPE: i32 = 4;
const LAVA_SLOPE: i32 = 2;

const MAX_UPDATES: usize = 8192;

const HORIZONTAL: [(i32, i32); 4] = [(0, -1), (0, 1), (-1, 0), (1, 0)];
const AROUND: [(i32, i32, i32); 6] = [(0, -1, 0), (0, 1, 0), (0, 0, -1), (0, 0, 1), (-1, 0, 0), (1, 0, 0)];

#[derive(Clone, Copy, Default)]
pub struct Info {
    pub kind: u8,
    pub level: u8,
    pub extra: u32,
}

const UNKNOWN: Info = Info { kind: SOLID, level: 0, extra: 0 };

#[derive(Default)]
pub struct Table {
    info: HashMap<u32, Info>,
    water: [u32; 16],
    flowing_water: [u32; 16],
    lava: [u32; 16],
    flowing_lava: [u32; 16],
    bubble_column: [u32; 2],
    stone: u32,
    cobblestone: u32,
    obsidian: u32,
    basalt: u32,
    air: u32,
}

static TABLE: RwLock<Option<Arc<Table>>> = RwLock::new(None);

pub fn learn(entries: &[(u32, Info)], results: [u32; 5]) {
    let mut table = Table { stone: results[0], cobblestone: results[1], obsidian: results[2], basalt: results[3], air: results[4], ..Default::default() };

    for &(state, info) in entries {
        let level = (info.level & 15) as usize;

        match info.kind {
            WATER => table.water[level] = state,
            FLOWING_WATER => table.flowing_water[level] = state,
            LAVA => table.lava[level] = state,
            FLOWING_LAVA => table.flowing_lava[level] = state,
            BUBBLE_COLUMN => table.bubble_column[level.min(1)] = state,
            _ => {}
        }

        table.info.insert(state, info);
    }

    let mut guard = TABLE.write().unwrap_or_else(|poisoned| poisoned.into_inner());
    *guard = Some(Arc::new(table));
}

fn table() -> Arc<Table> {
    let guard = TABLE.read().unwrap_or_else(|poisoned| poisoned.into_inner());
    guard.clone().unwrap_or_default()
}

pub trait View {
    fn block(&self, x: i32, y: i32, z: i32, layer: usize) -> Option<u32>;
}

#[derive(Clone, Copy, PartialEq)]
enum Fluid {
    Water,
    Lava,
}

impl Fluid {
    fn delay(self) -> u64 {
        if self == Fluid::Water { WATER_DELAY } else { LAVA_DELAY }
    }

    fn drop_off(self) -> u8 {
        if self == Fluid::Water { 1 } else { 2 }
    }

    fn slope(self) -> i32 {
        if self == Fluid::Water { WATER_SLOPE } else { LAVA_SLOPE }
    }
}

pub struct Change {
    pub x: i32,
    pub y: i32,
    pub z: i32,
    pub state: u32,
    pub flags: u8,
}

struct Batch<'a, V: View> {
    view: &'a V,
    table: &'a Table,
    written: HashMap<(i32, i32, i32), u32>,
    changes: Vec<Change>,
}

impl<V: View> Batch<'_, V> {
    fn state(&self, x: i32, y: i32, z: i32) -> Option<u32> {
        if !(MIN_Y..=MAX_Y).contains(&y) {
            return None;
        }

        if let Some(state) = self.written.get(&(x, y, z)) {
            return Some(*state);
        }

        self.view.block(x, y, z, 0)
    }

    fn info(&self, x: i32, y: i32, z: i32) -> Info {
        match self.state(x, y, z) {
            Some(state) => *self.table.info.get(&state).unwrap_or(&UNKNOWN),
            None => UNKNOWN,
        }
    }

    fn waterlogged(&self, x: i32, y: i32, z: i32) -> bool {
        if self.written.contains_key(&(x, y, z)) {
            return false;
        }

        match self.view.block(x, y, z, 1) {
            Some(state) => self.table.info.get(&state).is_some_and(|info| matches!(info.kind, WATER | FLOWING_WATER) && info.level == 0),
            None => false,
        }
    }

    fn is_water(&self, x: i32, y: i32, z: i32) -> bool {
        matches!(self.info(x, y, z).kind, WATER | FLOWING_WATER | WATERY | BUBBLE_COLUMN) || self.waterlogged(x, y, z)
    }

    fn depth(&self, x: i32, y: i32, z: i32, fluid: Fluid) -> Option<u8> {
        let info = self.info(x, y, z);

        match (fluid, info.kind) {
            (Fluid::Water, WATER | FLOWING_WATER) | (Fluid::Lava, LAVA | FLOWING_LAVA) => Some(info.level),
            (Fluid::Water, WATERY | BUBBLE_COLUMN) => Some(0),
            (Fluid::Water, _) if self.waterlogged(x, y, z) => Some(0),
            _ => None,
        }
    }

    fn is_blocked(&self, x: i32, y: i32, z: i32) -> bool {
        !matches!(self.info(x, y, z).kind, EMPTY | FLOODABLE | WATER | FLOWING_WATER | LAVA | FLOWING_LAVA | WATERY | BUBBLE_COLUMN)
    }

    fn write(&mut self, x: i32, y: i32, z: i32, state: u32, flags: u8) {
        self.written.insert((x, y, z), state);
        self.changes.push(Change { x, y, z, state, flags });
    }
}

const FROZEN_DELAY: u64 = 20;

#[derive(Default)]
pub struct LiquidWorld {
    scheduled: HashMap<(i32, i32, i32), u64>,
    queue: BinaryHeap<Reverse<(u64, i32, i32, i32)>>,
    random: u64,
    ticking: Option<HashSet<(i32, i32)>>,
}

impl LiquidWorld {
    pub fn new() -> Self {
        LiquidWorld { random: 0x2545F4914F6CDD1D, ..Default::default() }
    }

        pub fn pending(&self) -> usize {
            self.scheduled.len()
        }

        pub fn set_ticking(&mut self, chunks: HashSet<(i32, i32)>) {
            self.ticking = Some(chunks);
        }

    fn chance(&mut self, one_in: u64) -> bool {
        self.random ^= self.random << 13;
        self.random ^= self.random >> 7;
        self.random ^= self.random << 17;
        self.random % one_in == 0
    }

    fn schedule(&mut self, x: i32, y: i32, z: i32, due: u64) {
        if self.scheduled.get(&(x, y, z)).is_some_and(|existing| *existing <= due) {
            return;
        }

        self.scheduled.insert((x, y, z), due);
        self.queue.push(Reverse((due, x, y, z)));
    }

    fn wake<V: View>(&mut self, batch: &Batch<V>, x: i32, y: i32, z: i32, now: u64) {
        let info = batch.info(x, y, z);

        let delay = match info.kind {
            WATER | FLOWING_WATER | WATERY | BUBBLE_COLUMN => WATER_DELAY,
            LAVA | FLOWING_LAVA if self.mixes(batch, x, y, z) => 1,
            LAVA | FLOWING_LAVA => LAVA_DELAY,
            CONCRETE_POWDER => POWDER_DELAY,
            _ if batch.waterlogged(x, y, z) => WATER_DELAY,
            _ => return,
        };

        self.schedule(x, y, z, now + delay);
    }

    fn wake_around<V: View>(&mut self, batch: &Batch<V>, x: i32, y: i32, z: i32, now: u64) {
        for (dx, dy, dz) in AROUND {
            self.wake(batch, x + dx, y + dy, z + dz, now);
        }
    }

    pub fn changed<V: View>(&mut self, view: &V, x: i32, y: i32, z: i32, now: u64) {
        let table = table();
        let batch = Batch { view, table: &table, written: HashMap::new(), changes: Vec::new() };

        self.wake(&batch, x, y, z, now);
        self.wake_around(&batch, x, y, z, now);
    }

    pub fn add_chunk(&mut self, chunk_x: i32, chunk_z: i32, chunk: &Chunk, now: u64) {
        let table = table();

        for (sub, subchunk) in chunk.subchunks.iter().enumerate() {
            let Some(storage) = subchunk.layers.first() else { continue };
            let flowing = |state: &u32| table.info.get(state).is_some_and(|info| matches!(info.kind, FLOWING_WATER | FLOWING_LAVA));

            if !storage.palette.iter().any(flowing) {
                continue;
            }

            let bottom = (sub as i32 + MIN_SUBCHUNK) << 4;

            for index in 0..4096 {
                let state = storage.get(index);

                if let Some(info) = table.info.get(&state).filter(|_| flowing(&state)) {
                    let (x, y, z) = ((chunk_x << 4) + (index >> 8) as i32, bottom + (index & 15) as i32, (chunk_z << 4) + ((index >> 4) & 15) as i32);
                    self.schedule(x, y, z, now + if info.kind == FLOWING_WATER { WATER_DELAY } else { LAVA_DELAY });
                }
            }
        }
    }

    pub fn tick<V: View>(&mut self, view: &V, now: u64) -> Vec<Change> {
        let table = table();
        let mut batch = Batch { view, table: &table, written: HashMap::new(), changes: Vec::new() };
        let mut updates = 0;
        let mut frozen = Vec::new();

        while let Some(Reverse((due, x, y, z))) = self.queue.peek().copied() {
            if due > now || updates >= MAX_UPDATES {
                break;
            }

            self.queue.pop();

            if self.scheduled.get(&(x, y, z)) != Some(&due) {
                continue;
            }

            self.scheduled.remove(&(x, y, z));

            if self.ticking.as_ref().is_some_and(|ticking| !ticking.contains(&(x >> 4, z >> 4))) {
                frozen.push((x, y, z));
                continue;
            }

            if view.block(x, y, z, 0).is_none() {
                continue;
            }

            updates += 1;
            self.update(&mut batch, x, y, z, now);
        }

        for (x, y, z) in frozen {
            self.schedule(x, y, z, now + FROZEN_DELAY);
        }

        batch.changes
    }

    fn update<V: View>(&mut self, batch: &mut Batch<V>, x: i32, y: i32, z: i32, now: u64) {
        let info = batch.info(x, y, z);

        match info.kind {
            WATER | FLOWING_WATER if info.level == 0 && self.bubble_up(batch, x, y, z, info, now) => {}
            WATER | FLOWING_WATER => self.flow(batch, x, y, z, Fluid::Water, info, now),
            LAVA | FLOWING_LAVA => {
                if !self.mix(batch, x, y, z, info.level, now) {
                    self.flow(batch, x, y, z, Fluid::Lava, info, now);
                }
            }
            BUBBLE_COLUMN => {
                if !self.bubble_up(batch, x, y, z, info, now) {
                    self.spread(batch, x, y, z, Fluid::Water, 0, now);
                }
            }
            WATERY => self.spread(batch, x, y, z, Fluid::Water, 0, now),
            CONCRETE_POWDER => {
                let touched = AROUND.iter().any(|&(dx, dy, dz)| dy != -1 && batch.is_water(x + dx, y + dy, z + dz));

                if touched {
                    batch.write(x, y, z, info.extra, 0);
                    self.wake_around(batch, x, y, z, now);
                }
            }
            _ if batch.waterlogged(x, y, z) => self.spread(batch, x, y, z, Fluid::Water, 0, now),
            _ => {}
        }
    }

    fn mixes<V: View>(&self, batch: &Batch<V>, x: i32, y: i32, z: i32) -> bool {
        let sides = [(0, 1, 0), (0, 0, -1), (0, 0, 1), (-1, 0, 0), (1, 0, 0)];
        let on_soul_soil = batch.info(x, y - 1, z).kind == SOUL_SOIL;

        sides.iter().any(|&(dx, dy, dz)| batch.is_water(x + dx, y + dy, z + dz) || (on_soul_soil && batch.info(x + dx, y + dy, z + dz).kind == BLUE_ICE))
    }

    fn mix<V: View>(&mut self, batch: &mut Batch<V>, x: i32, y: i32, z: i32, level: u8, now: u64) -> bool {
        let sides = [(0, 1, 0), (0, 0, -1), (0, 0, 1), (-1, 0, 0), (1, 0, 0)];
        let on_soul_soil = batch.info(x, y - 1, z).kind == SOUL_SOIL;

        for (dx, dy, dz) in sides {
            let hardened = if batch.is_water(x + dx, y + dy, z + dz) {
                if level == 0 { batch.table.obsidian } else { batch.table.cobblestone }
            } else if on_soul_soil && batch.info(x + dx, y + dy, z + dz).kind == BLUE_ICE {
                batch.table.basalt
            } else {
                continue;
            };

            batch.write(x, y, z, hardened, FIZZ);
            self.wake_around(batch, x, y, z, now);

            return true;
        }

        false
    }

    fn bubble_up<V: View>(&mut self, batch: &mut Batch<V>, x: i32, y: i32, z: i32, info: Info, now: u64) -> bool {
        let below = batch.info(x, y - 1, z);

        let wanted = match below.kind {
            SOUL_SAND => Some(0),
            MAGMA => Some(1),
            BUBBLE_COLUMN => Some(below.level),
            _ => None,
        };

        let state = match (info.kind, wanted) {
            (BUBBLE_COLUMN, None) => batch.table.water[0],
            (BUBBLE_COLUMN, Some(drag)) if drag != info.level => batch.table.bubble_column[drag as usize],
            (WATER | FLOWING_WATER, Some(drag)) => batch.table.bubble_column[drag as usize],
            _ => return false,
        };

        if state == 0 {
            return false;
        }

        batch.write(x, y, z, state, 0);
        self.wake_around(batch, x, y, z, now);

        true
    }

    fn flow<V: View>(&mut self, batch: &mut Batch<V>, x: i32, y: i32, z: i32, fluid: Fluid, info: Info, now: u64) {
        let mut level = info.level as i32;
        let flowing = matches!(info.kind, FLOWING_WATER | FLOWING_LAVA);

        if level > 0 {
            let mut sources = 0;
            let mut lowest = -100;

            for (dx, dz) in HORIZONTAL {
                let Some(depth) = batch.depth(x + dx, y, z + dz, fluid) else { continue };

                if depth == 0 {
                    sources += 1;
                }

                let depth = if depth >= 8 { 0 } else { depth as i32 };

                if lowest < 0 || depth < lowest {
                    lowest = depth;
                }
            }

            let mut new_level = lowest + fluid.drop_off() as i32;

            if new_level >= 8 || lowest < 0 {
                new_level = -1;
            }

            if let Some(above) = batch.depth(x, y + 1, z, fluid) {
                new_level = if above >= 8 { above as i32 } else { above as i32 + 8 };
            }

            if sources >= 2 && fluid == Fluid::Water && (batch.is_blocked(x, y - 1, z) || batch.depth(x, y - 1, z, fluid) == Some(0)) {
                new_level = 0;
            }

            let mut delay = fluid.delay();

            if fluid == Fluid::Lava && level < 8 && new_level < 8 && new_level > level && !self.chance(4) {
                delay *= 4;
            }

            if new_level == level {
                self.settle(batch, x, y, z, fluid, level as u8, flowing);
            } else if new_level < 0 {
                batch.write(x, y, z, batch.table.air, 0);
                self.wake_around(batch, x, y, z, now);
                return;
            } else {
                level = new_level;
                batch.write(x, y, z, Self::flowing_state(batch.table, fluid, level as u8), 0);
                self.schedule(x, y, z, now + delay);
                self.wake_around(batch, x, y, z, now);
            }
        } else {
            self.settle(batch, x, y, z, fluid, 0, flowing);
        }

        self.spread(batch, x, y, z, fluid, level as u8, now);
    }

    fn settle<V: View>(&mut self, batch: &mut Batch<V>, x: i32, y: i32, z: i32, fluid: Fluid, level: u8, flowing: bool) {
        if flowing {
            let still = if fluid == Fluid::Water { batch.table.water[level as usize] } else { batch.table.lava[level as usize] };
            batch.write(x, y, z, still, 0);
        }
    }

    fn flowing_state(table: &Table, fluid: Fluid, level: u8) -> u32 {
        if fluid == Fluid::Water { table.flowing_water[level as usize & 15] } else { table.flowing_lava[level as usize & 15] }
    }

    fn spread<V: View>(&mut self, batch: &mut Batch<V>, x: i32, y: i32, z: i32, fluid: Fluid, level: u8, now: u64) {
        let fell = self.flow_into(batch, x, y - 1, z, fluid, true, if level >= 8 { level } else { level + 8 }, now);

        if level != 0 && (fell || !batch.is_blocked(x, y - 1, z)) {
            return;
        }

        let next = if level >= 8 { 1 } else { level + fluid.drop_off() };

        if next >= 8 {
            return;
        }

        for (dx, dz) in self.flow_directions(batch, x, y, z, fluid) {
            self.flow_into(batch, x + dx, y, z + dz, fluid, false, next, now);
        }
    }

    fn flow_into<V: View>(&mut self, batch: &mut Batch<V>, x: i32, y: i32, z: i32, fluid: Fluid, down: bool, level: u8, now: u64) -> bool {
        let info = batch.info(x, y, z);

        if batch.state(x, y, z).is_none() {
            return false;
        }

        match info.kind {
            EMPTY | FLOODABLE => {
                let flags = match (fluid, info.kind) {
                    (Fluid::Water, FLOODABLE) => DROPS,
                    (Fluid::Lava, FLOODABLE) => FIZZ,
                    _ => 0,
                };

                batch.write(x, y, z, Self::flowing_state(batch.table, fluid, level), flags);
                self.schedule(x, y, z, now + fluid.delay());
                self.wake_around(batch, x, y, z, now);
                true
            }

            WATER | FLOWING_WATER if fluid == Fluid::Lava && down => {
                batch.write(x, y, z, batch.table.stone, FIZZ);
                self.wake_around(batch, x, y, z, now);
                true
            }
            _ => false,
        }
    }

    fn can_flow_through<V: View>(batch: &Batch<V>, x: i32, y: i32, z: i32, fluid: Fluid) -> bool {
        !batch.is_blocked(x, y, z) && batch.state(x, y, z).is_some() && batch.depth(x, y, z, fluid) != Some(0)
    }

    fn flow_directions<V: View>(&self, batch: &Batch<V>, x: i32, y: i32, z: i32, fluid: Fluid) -> Vec<(i32, i32)> {
        let mut best = 1000;
        let mut directions = Vec::with_capacity(4);

        for (i, (dx, dz)) in HORIZONTAL.iter().enumerate() {
            let (nx, nz) = (x + dx, z + dz);

            if !Self::can_flow_through(batch, nx, y, nz, fluid) {
                continue;
            }

            let distance = if batch.is_blocked(nx, y - 1, nz) { Self::slope_distance(batch, nx, y, nz, 1, i, fluid) } else { 0 };

            if distance < best {
                directions.clear();
                best = distance;
            }

            if distance <= best {
                directions.push((*dx, *dz));
            }
        }

        directions
    }

    fn slope_distance<V: View>(batch: &Batch<V>, x: i32, y: i32, z: i32, distance: i32, from: usize, fluid: Fluid) -> i32 {
        let mut best = 1000;
        let back = from ^ 1;

        for (i, (dx, dz)) in HORIZONTAL.iter().enumerate() {
            if i == back {
                continue;
            }

            let (nx, nz) = (x + dx, z + dz);

            if !Self::can_flow_through(batch, nx, y, nz, fluid) {
                continue;
            }

            if !batch.is_blocked(nx, y - 1, nz) {
                return distance;
            }

            if distance < fluid.slope() {
                best = best.min(Self::slope_distance(batch, nx, y, nz, distance + 1, i, fluid));
            }
        }

        best
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    const AIR: u32 = 1;
    const STONE: u32 = 2;
    const COBBLE: u32 = 3;
    const OBSIDIAN: u32 = 4;
    const BASALT: u32 = 5;
    const FLOWER: u32 = 6;
    const SOUL_SAND_STATE: u32 = 7;
    const POWDER: u32 = 8;
    const CONCRETE: u32 = 9;
    const BUBBLE_UP: u32 = 10;
    const BUBBLE_DOWN: u32 = 11;

    fn water(level: u8) -> u32 { 100 + level as u32 }
    fn flowing_water(level: u8) -> u32 { 200 + level as u32 }
    fn lava(level: u8) -> u32 { 300 + level as u32 }
    fn flowing_lava(level: u8) -> u32 { 400 + level as u32 }

    fn teach() {
        let mut entries = vec![
            (AIR, Info { kind: EMPTY, level: 0, extra: 0 }),
            (STONE, Info { kind: SOLID, level: 0, extra: 0 }),
            (FLOWER, Info { kind: FLOODABLE, level: 0, extra: 0 }),
            (SOUL_SAND_STATE, Info { kind: SOUL_SAND, level: 0, extra: 0 }),
            (POWDER, Info { kind: CONCRETE_POWDER, level: 0, extra: CONCRETE }),
            (BUBBLE_UP, Info { kind: BUBBLE_COLUMN, level: 0, extra: 0 }),
            (BUBBLE_DOWN, Info { kind: BUBBLE_COLUMN, level: 1, extra: 0 }),
        ];

        for level in 0..16 {
            entries.push((water(level), Info { kind: WATER, level, extra: 0 }));
            entries.push((flowing_water(level), Info { kind: FLOWING_WATER, level, extra: 0 }));
            entries.push((lava(level), Info { kind: LAVA, level, extra: 0 }));
            entries.push((flowing_lava(level), Info { kind: FLOWING_LAVA, level, extra: 0 }));
        }

        learn(&entries, [STONE, COBBLE, OBSIDIAN, BASALT, AIR]);
    }

    struct Map(HashMap<(i32, i32, i32), u32>);

    impl View for Map {
        fn block(&self, x: i32, y: i32, z: i32, layer: usize) -> Option<u32> {
            if layer > 0 {
                return Some(AIR);
            }

            if !(-64..=64).contains(&x) || !(-64..=64).contains(&z) {
                return None;
            }

            Some(*self.0.get(&(x, y, z)).unwrap_or(&if y < 0 { STONE } else { AIR }))
        }
    }

    fn run(world: &mut LiquidWorld, map: &mut Map, now: &mut u64, limit: u64) {
        for _ in 0..limit {
            *now += 1;

            for change in world.tick(map, *now) {
                map.0.insert((change.x, change.y, change.z), change.state);
            }

            if world.pending() == 0 {
                break;
            }
        }
    }

    fn place(world: &mut LiquidWorld, map: &mut Map, x: i32, y: i32, z: i32, state: u32, now: u64) {
        map.0.insert((x, y, z), state);
        world.changed(map, x, y, z, now);
    }

    fn get(map: &Map, x: i32, y: i32, z: i32) -> u32 {
        map.block(x, y, z, 0).unwrap()
    }

    #[test]
    fn water_on_flat_ground_spreads_seven_blocks() {
        teach();
        let (mut world, mut map, mut now) = (LiquidWorld::new(), Map(HashMap::new()), 0);
        place(&mut world, &mut map, 0, 0, 0, water(0), now);
        run(&mut world, &mut map, &mut now, 500);

        assert_eq!(get(&map, 0, 0, 0), water(0));
        assert_eq!(get(&map, 3, 0, 0), water(3));
        assert_eq!(get(&map, 7, 0, 0), water(7));
        assert_eq!(get(&map, 8, 0, 0), AIR);
        assert_eq!(get(&map, 3, 0, 4), water(7), "diamond shaped, seven steps out");
    }

    #[test]
    fn lava_spreads_three_blocks() {
        teach();
        let (mut world, mut map, mut now) = (LiquidWorld::new(), Map(HashMap::new()), 0);
        place(&mut world, &mut map, 0, 0, 0, lava(0), now);
        run(&mut world, &mut map, &mut now, 2000);

        assert_eq!(get(&map, 3, 0, 0), lava(6));
        assert_eq!(get(&map, 4, 0, 0), AIR);
    }

    #[test]
    fn removing_the_source_drains_the_water() {
        teach();
        let (mut world, mut map, mut now) = (LiquidWorld::new(), Map(HashMap::new()), 0);
        place(&mut world, &mut map, 0, 0, 0, water(0), now);
        run(&mut world, &mut map, &mut now, 500);
        place(&mut world, &mut map, 0, 0, 0, AIR, now);
        run(&mut world, &mut map, &mut now, 500);

        for x in -8..=8 {
            assert_eq!(get(&map, x, 0, 0), AIR, "at {x}");
        }
    }

    #[test]
    fn two_sources_make_a_third() {
        teach();
        let (mut world, mut map, mut now) = (LiquidWorld::new(), Map(HashMap::new()), 0);

        for x in -3..=3 {
            for z in -3..=3 {
                map.0.insert((x, 1, z), STONE);
                map.0.insert((x, 0, z), STONE);
            }
        }

        for x in -1..=1 {
            map.0.insert((x, 1, 0), AIR);
        }

        place(&mut world, &mut map, -1, 1, 0, water(0), now);
        place(&mut world, &mut map, 1, 1, 0, water(0), now);
        run(&mut world, &mut map, &mut now, 500);

        assert!(matches!(get(&map, 0, 1, 0), s if s == water(0) || s == flowing_water(0)));
    }

    #[test]
    fn water_falls_and_spreads_where_it_lands() {
        teach();
        let (mut world, mut map, mut now) = (LiquidWorld::new(), Map(HashMap::new()), 0);
        map.0.insert((0, 9, 0), STONE);
        place(&mut world, &mut map, 0, 10, 0, water(0), now);
        run(&mut world, &mut map, &mut now, 2000);

        assert_eq!(get(&map, 1, 9, 0), water(9), "falling down the side");
        assert_eq!(get(&map, 1, 0, 0), water(9));
        assert_eq!(get(&map, 2, 0, 0), water(1));
    }

    #[test]
    fn water_flows_toward_the_nearest_hole() {
        teach();
        let (mut world, mut map, mut now) = (LiquidWorld::new(), Map(HashMap::new()), 0);
        map.0.insert((3, -1, 0), AIR);
        place(&mut world, &mut map, 0, 0, 0, water(0), now);
        //only the first round of flowing
        now += WATER_DELAY;

        for change in world.tick(&map, now) {
            map.0.insert((change.x, change.y, change.z), change.state);
        }

        assert_eq!(get(&map, 1, 0, 0), flowing_water(1));
        assert_eq!(get(&map, -1, 0, 0), AIR, "only towards the hole");
    }

    #[test]
    fn water_washes_away_flowers() {
        teach();
        let (mut world, mut map, mut now) = (LiquidWorld::new(), Map(HashMap::new()), 0);
        map.0.insert((1, 0, 0), FLOWER);
        place(&mut world, &mut map, 0, 0, 0, water(0), now);
        now += WATER_DELAY;
        let changes = world.tick(&map, now);

        assert!(changes.iter().any(|c| (c.x, c.y, c.z) == (1, 0, 0) && c.flags & DROPS != 0));
    }

    #[test]
    fn lava_and_water_make_stone() {
        teach();
        let (mut world, mut map, mut now) = (LiquidWorld::new(), Map(HashMap::new()), 0);
        place(&mut world, &mut map, 0, 0, 0, lava(0), now);
        place(&mut world, &mut map, 1, 0, 0, water(0), now);
        run(&mut world, &mut map, &mut now, 50);
        assert_eq!(get(&map, 0, 0, 0), OBSIDIAN);

        let (mut world, mut map, mut now) = (LiquidWorld::new(), Map(HashMap::new()), 0);
        place(&mut world, &mut map, 0, 0, 0, lava(0), now);
        run(&mut world, &mut map, &mut now, 2000);
        place(&mut world, &mut map, 5, 0, 0, water(0), now);
        run(&mut world, &mut map, &mut now, 2000);
        assert!(get(&map, 2, 0, 0) == COBBLE || get(&map, 3, 0, 0) == COBBLE, "flowing lava hardens into cobblestone");

        let (mut world, mut map, mut now) = (LiquidWorld::new(), Map(HashMap::new()), 0);
        map.0.insert((0, 5, 0), STONE);
        place(&mut world, &mut map, 0, 0, 1, water(0), now);
        run(&mut world, &mut map, &mut now, 500);
        place(&mut world, &mut map, 0, 6, 1, lava(0), now);
        run(&mut world, &mut map, &mut now, 3000);
        assert_eq!(get(&map, 0, 0, 1), STONE, "lava falling into water");
    }

    #[test]
    fn concrete_powder_hardens_and_bubbles_rise() {
        teach();
        let (mut world, mut map, mut now) = (LiquidWorld::new(), Map(HashMap::new()), 0);
        map.0.insert((1, 0, 0), POWDER);
        place(&mut world, &mut map, 0, 0, 0, water(0), now);
        run(&mut world, &mut map, &mut now, 50);
        assert_eq!(get(&map, 1, 0, 0), CONCRETE);

        let (mut world, mut map, mut now) = (LiquidWorld::new(), Map(HashMap::new()), 0);

        for y in 0..5 {
            map.0.insert((0, y, 0), water(0));

            for (dx, dz) in HORIZONTAL {
                map.0.insert((dx, y, dz), STONE);
            }
        }

        place(&mut world, &mut map, 0, -1, 0, SOUL_SAND_STATE, now);
        run(&mut world, &mut map, &mut now, 200);

        for y in 0..5 {
            assert_eq!(get(&map, 0, y, 0), BUBBLE_UP, "at {y}");
        }

        place(&mut world, &mut map, 0, -1, 0, STONE, now);
        run(&mut world, &mut map, &mut now, 200);
        assert_eq!(get(&map, 0, 4, 0), water(0));
        let _ = BUBBLE_DOWN;
    }
}