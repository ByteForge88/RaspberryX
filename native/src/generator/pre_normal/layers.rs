//! The biome map of the 1.8 world generator: a stack of layers, each one working on the grid of
//! the one under it, from continents of 1 block per 4096 down to single blocks. Every layer
//! is a pure function of the position, so any area can be asked for at any time.
//!
//! The layers are written the way the game has them (cubiomes, MIT, was the reference to test
//! against), down to the zoom layers of the hills branch never getting the world seed.

use std::sync::Arc;

use super::biomes::{self, id};

const MULTIPLIER: i64 = 6364136223846793005;
const INCREMENT: i64 = 1442695040888963407;

#[inline]
fn step(value: i64) -> i64 {
    value.wrapping_mul(value.wrapping_mul(MULTIPLIER).wrapping_add(INCREMENT))
}

fn base_seed(salt: i64) -> i64 {
    let mut seed = salt;

    for _ in 0..3 {
        seed = step(seed).wrapping_add(salt);
    }

    seed
}

fn world_gen_seed(seed: i64, base: i64) -> i64 {
    let mut value = seed;

    for _ in 0..3 {
        value = step(value).wrapping_add(base);
    }

    value
}

/// The random of a layer, seeded again for each cell.
struct Cell {
    seed: i64,
    world: i64,
}

impl Cell {
    #[inline]
    fn new(world: i64, x: i32, z: i32) -> Self {
        let (x, z) = (x as i64, z as i64);
        let mut seed = world;
        seed = step(seed).wrapping_add(x);
        seed = step(seed).wrapping_add(z);
        seed = step(seed).wrapping_add(x);
        seed = step(seed).wrapping_add(z);
        Cell { seed, world }
    }

    #[inline]
    fn next(&mut self, bound: i32) -> i32 {
        let mut value = ((self.seed >> 24) % bound as i64) as i32;

        if value < 0 {
            value += bound;
        }

        self.seed = step(self.seed).wrapping_add(self.world);
        value
    }

    fn pick2(&mut self, a: i32, b: i32) -> i32 {
        if self.next(2) == 0 { a } else { b }
    }

    fn pick4(&mut self, a: i32, b: i32, c: i32, d: i32) -> i32 {
        match self.next(4) {
            0 => a,
            1 => b,
            2 => c,
            _ => d,
        }
    }

    /// The value most of the four agree on, a random one of them if none wins.
    fn mode_or_random(&mut self, a: i32, b: i32, c: i32, d: i32) -> i32 {
        if b == c && c == d {
            b
        } else if a == b && a == c {
            a
        } else if a == b && a == d {
            a
        } else if a == c && a == d {
            a
        } else if a == b && c != d {
            a
        } else if a == c && b != d {
            a
        } else if a == d && b != c {
            a
        } else if b == c && a != d {
            b
        } else if b == d && a != c {
            b
        } else if c == d && a != b {
            c
        } else {
            self.pick4(a, b, c, d)
        }
    }
}

#[derive(Clone, Copy, PartialEq, Eq, Debug)]
enum Kind {
    Island,
    FuzzyZoom,
    Zoom,
    AddIsland,
    RemoveTooMuchOcean,
    AddSnow,
    CoolWarm,
    HeatIce,
    Special,
    AddMushroomIsland,
    DeepOcean,
    RiverInit,
    Biome,
    BiomeEdge,
    Hills,
    River,
    Smooth,
    RareBiome,
    Shore,
    RiverMix,
    Voronoi,
}

struct Layer {
    kind: Kind,
    /// what the game calls the world gen seed, 0 for layers it never hands the seed to
    seed: i64,
    parent: Option<Arc<Layer>>,
    other: Option<Arc<Layer>>,
}

fn layer(kind: Kind, salt: i64, world_seed: Option<i64>, parent: Option<Arc<Layer>>) -> Arc<Layer> {
    layer2(kind, salt, world_seed, parent, None)
}

fn layer2(kind: Kind, salt: i64, world_seed: Option<i64>, parent: Option<Arc<Layer>>, other: Option<Arc<Layer>>) -> Arc<Layer> {
    let seed = world_seed.map_or(0, |world| world_gen_seed(world, base_seed(salt)));
    Arc::new(Layer { kind, seed, parent, other })
}

fn magnify(salt: i64, world_seed: Option<i64>, mut parent: Arc<Layer>, times: i32) -> Arc<Layer> {
    for i in 0..times {
        parent = layer(Kind::Zoom, salt + i as i64, world_seed, Some(parent));
    }

    parent
}

impl Layer {
    fn parent(&self, x: i32, z: i32, w: i32, h: i32) -> Vec<i32> {
        self.parent.as_ref().expect("layer without a parent").get(x, z, w, h)
    }

    fn get(&self, x: i32, z: i32, w: i32, h: i32) -> Vec<i32> {
        match self.kind {
            Kind::Island => self.island(x, z, w, h),
            Kind::FuzzyZoom => self.zoom(x, z, w, h, true),
            Kind::Zoom => self.zoom(x, z, w, h, false),
            Kind::Voronoi => self.voronoi(x, z, w, h),
            Kind::Special | Kind::RiverInit | Kind::Biome | Kind::RareBiome => self.same_area(x, z, w, h),
            Kind::RiverMix => self.river_mix(x, z, w, h),
            _ => self.around(x, z, w, h),
        }
    }

    fn island(&self, x: i32, z: i32, w: i32, h: i32) -> Vec<i32> {
        let mut out = vec![0; (w * h) as usize];

        for j in 0..h {
            for i in 0..w {
                let mut cell = Cell::new(self.seed, x + i, z + j);
                out[(i + j * w) as usize] = (cell.next(10) == 0) as i32;
            }
        }

        if x > -w && x <= 0 && z > -h && z <= 0 {
            out[(-x - z * w) as usize] = 1;
        }

        out
    }

    fn zoom(&self, x: i32, z: i32, w: i32, h: i32, fuzzy: bool) -> Vec<i32> {
        let (px, pz) = (x >> 1, z >> 1);
        let (pw, ph) = ((w >> 1) + 2, (h >> 1) + 2);
        let parent = self.parent(px, pz, pw, ph);
        let nw = (pw - 1) << 1;
        let nh = (ph - 1) << 1;
        let mut grid = vec![0; (nw * nh) as usize];

        for k in 0..ph - 1 {
            let mut index = ((k << 1) * nw) as usize;
            let mut a = parent[(k * pw) as usize];
            let mut c = parent[((k + 1) * pw) as usize];

            for i in 0..pw - 1 {
                let mut cell = Cell::new(self.seed, (i + px) << 1, (k + pz) << 1);
                let b = parent[(i + 1 + k * pw) as usize];
                let d = parent[(i + 1 + (k + 1) * pw) as usize];

                grid[index] = a;
                grid[index + nw as usize] = cell.pick2(a, c);
                index += 1;
                grid[index] = cell.pick2(a, b);
                grid[index + nw as usize] = if fuzzy { cell.pick4(a, b, c, d) } else { cell.mode_or_random(a, b, c, d) };
                index += 1;

                a = b;
                c = d;
            }
        }

        let mut out = vec![0; (w * h) as usize];

        for j in 0..h {
            let from = ((j + (z & 1)) * nw + (x & 1)) as usize;
            out[(j * w) as usize..((j + 1) * w) as usize].copy_from_slice(&grid[from..from + w as usize]);
        }

        out
    }

    /// Layers that look at a cell only.
    fn same_area(&self, x: i32, z: i32, w: i32, h: i32) -> Vec<i32> {
        let mut out = self.parent(x, z, w, h);

        for j in 0..h {
            for i in 0..w {
                let index = (i + j * w) as usize;
                let mut cell = Cell::new(self.seed, x + i, z + j);
                let value = out[index];

                out[index] = match self.kind {
                    Kind::Special => {
                        if value != 0 && cell.next(13) == 0 {
                            value | (((1 + cell.next(15)) << 8) & 0xf00)
                        } else {
                            value
                        }
                    }
                    Kind::RiverInit => {
                        if value > 0 { cell.next(299999) + 2 } else { 0 }
                    }
                    Kind::RareBiome => {
                        if cell.next(57) == 0 && value == id::PLAINS { value + 128 } else { value }
                    }
                    _ => biome(&mut cell, value),
                };
            }
        }

        out
    }

    /// Layers that look at a cell and the ones around it.
    fn around(&self, x: i32, z: i32, w: i32, h: i32) -> Vec<i32> {
        let pw = w + 2;
        let parent = self.parent(x - 1, z - 1, pw, h + 2);
        let rivers = if self.kind == Kind::Hills { self.other.as_ref().expect("hills without rivers").get(x - 1, z - 1, pw, h + 2) } else { Vec::new() };
        let mut out = vec![0; (w * h) as usize];

        for j in 0..h {
            for i in 0..w {
                let at = |dx: i32, dz: i32| parent[(i + 1 + dx + (j + 1 + dz) * pw) as usize];
                let center = at(0, 0);
                let (up, right, left, down) = (at(0, -1), at(1, 0), at(-1, 0), at(0, 1));
                let mut cell = Cell::new(self.seed, x + i, z + j);

                out[(i + j * w) as usize] = match self.kind {
                    Kind::AddIsland => add_island(&mut cell, center, at(-1, -1), at(1, -1), at(-1, 1), at(1, 1)),
                    Kind::RemoveTooMuchOcean => {
                        if center == 0 && up == 0 && right == 0 && left == 0 && down == 0 && cell.next(2) == 0 { 1 } else { center }
                    }
                    Kind::AddSnow => {
                        if center == 0 {
                            0
                        } else {
                            match cell.next(6) {
                                0 => 4,
                                1 => 3,
                                _ => 1,
                            }
                        }
                    }
                    Kind::CoolWarm => {
                        let near = [up, right, left, down];
                        if center == 1 && near.iter().any(|&value| value == 3 || value == 4) { 2 } else { center }
                    }
                    Kind::HeatIce => {
                        let near = [up, right, left, down];
                        if center == 4 && near.iter().any(|&value| value == 1 || value == 2) { 3 } else { center }
                    }
                    Kind::AddMushroomIsland => {
                        if center == 0 && at(-1, -1) == 0 && at(1, -1) == 0 && at(-1, 1) == 0 && at(1, 1) == 0 && cell.next(100) == 0 {
                            id::MUSHROOM_ISLAND
                        } else {
                            center
                        }
                    }
                    Kind::DeepOcean => {
                        let oceans = [up, right, left, down].iter().filter(|&&value| value == 0).count();
                        if center == 0 && oceans > 3 { id::DEEP_OCEAN } else { center }
                    }
                    Kind::BiomeEdge => biome_edge(center, up, right, left, down),
                    Kind::Hills => {
                        let near = [up, right, left, down];
                        hills(&mut cell, center, rivers[(i + 1 + (j + 1) * pw) as usize], &near)
                    }
                    Kind::River => {
                        let reduce = |value: i32| if value >= 2 { 2 + (value & 1) } else { value };
                        let c = reduce(center);
                        if c == reduce(left) && c == reduce(up) && c == reduce(right) && c == reduce(down) { -1 } else { id::RIVER }
                    }
                    Kind::Smooth => {
                        if left == right && up == down {
                            if cell.next(2) == 0 { left } else { up }
                        } else {
                            let mut value = center;
                            if left == right {
                                value = left;
                            }
                            if up == down {
                                value = up;
                            }
                            value
                        }
                    }
                    Kind::Shore => shore(center, up, right, left, down),
                    kind => unreachable!("{kind:?} does not look around"),
                };
            }
        }

        out
    }

    fn river_mix(&self, x: i32, z: i32, w: i32, h: i32) -> Vec<i32> {
        let mut out = self.parent(x, z, w, h);
        let rivers = self.other.as_ref().expect("river mix without rivers").get(x, z, w, h);

        for (value, river) in out.iter_mut().zip(rivers) {
            if *value != id::OCEAN && *value != id::DEEP_OCEAN && river == id::RIVER {
                *value = if *value == id::ICE_PLAINS {
                    id::FROZEN_RIVER
                } else if *value == id::MUSHROOM_ISLAND || *value == id::MUSHROOM_ISLAND_SHORE {
                    id::MUSHROOM_ISLAND_SHORE
                } else {
                    river & 255
                };
            }
        }

        out
    }

    fn voronoi(&self, x: i32, z: i32, w: i32, h: i32) -> Vec<i32> {
        let (x, z) = (x - 2, z - 2);
        let (px, pz) = (x >> 2, z >> 2);
        let (pw, ph) = ((w >> 2) + 2, (h >> 2) + 2);
        let parent = self.parent(px, pz, pw, ph);
        let nw = ((pw - 1) << 2) as usize;
        let nh = ((ph - 1) << 2) as usize;
        let mut grid = vec![0; nw * nh];

        let offset = |cell: &mut Cell| (cell.next(1024) as f64 / 1024.0 - 0.5) * 3.6;

        for k in 0..ph - 1 {
            let mut v00 = parent[(k * pw) as usize];
            let mut v01 = parent[((k + 1) * pw) as usize];

            for i in 0..pw - 1 {
                let mut cell = Cell::new(self.seed, (i + px) << 2, (k + pz) << 2);
                let ax = offset(&mut cell);
                let az = offset(&mut cell);
                let mut cell = Cell::new(self.seed, (i + px + 1) << 2, (k + pz) << 2);
                let bx = offset(&mut cell) + 4.0;
                let bz = offset(&mut cell);
                let mut cell = Cell::new(self.seed, (i + px) << 2, (k + pz + 1) << 2);
                let cx = offset(&mut cell);
                let cz = offset(&mut cell) + 4.0;
                let mut cell = Cell::new(self.seed, (i + px + 1) << 2, (k + pz + 1) << 2);
                let dx = offset(&mut cell) + 4.0;
                let dz = offset(&mut cell) + 4.0;

                let v10 = parent[(i + 1 + k * pw) as usize] & 255;
                let v11 = parent[(i + 1 + (k + 1) * pw) as usize] & 255;

                for row in 0..4 {
                    let mut index = (((k << 2) + row) as usize) * nw + ((i << 2) as usize);
                    let fz = row as f64;

                    for column in 0..4 {
                        let fx = column as f64;
                        let a = (fz - az) * (fz - az) + (fx - ax) * (fx - ax);
                        let b = (fz - bz) * (fz - bz) + (fx - bx) * (fx - bx);
                        let c = (fz - cz) * (fz - cz) + (fx - cx) * (fx - cx);
                        let d = (fz - dz) * (fz - dz) + (fx - dx) * (fx - dx);

                        grid[index] = if a < b && a < c && a < d {
                            v00
                        } else if b < a && b < c && b < d {
                            v10
                        } else if c < a && c < b && c < d {
                            v01
                        } else {
                            v11
                        };
                        index += 1;
                    }
                }

                v00 = v10;
                v01 = v11;
            }
        }

        let mut out = vec![0; (w * h) as usize];

        for j in 0..h as usize {
            let from = (j + (z & 3) as usize) * nw + (x & 3) as usize;
            out[j * w as usize..(j + 1) * w as usize].copy_from_slice(&grid[from..from + w as usize]);
        }

        out
    }
}

fn add_island(cell: &mut Cell, center: i32, v00: i32, v20: i32, v02: i32, v22: i32) -> i32 {
    if center != 0 || (v00 == 0 && v20 == 0 && v02 == 0 && v22 == 0) {
        if center > 0 && (v00 == 0 || v20 == 0 || v02 == 0 || v22 == 0) {
            if cell.next(5) == 0 {
                if center == 4 { 4 } else { 0 }
            } else {
                center
            }
        } else {
            center
        }
    } else {
        let mut count = 1;
        let mut pick = 1;

        for value in [v00, v20, v02, v22] {
            if value != 0 {
                if cell.next(count) == 0 {
                    pick = value;
                }
                count += 1;
            }
        }

        if cell.next(3) == 0 {
            pick
        } else if pick == 4 {
            4
        } else {
            0
        }
    }
}

const WARM: [i32; 6] = [id::DESERT, id::DESERT, id::DESERT, id::SAVANNA, id::SAVANNA, id::PLAINS];
const MEDIUM: [i32; 6] = [id::FOREST, id::ROOFED_FOREST, id::EXTREME_HILLS, id::PLAINS, id::BIRCH_FOREST, id::SWAMPLAND];
const COLD: [i32; 4] = [id::FOREST, id::EXTREME_HILLS, id::TAIGA, id::PLAINS];
const ICE: [i32; 4] = [id::ICE_PLAINS, id::ICE_PLAINS, id::ICE_PLAINS, id::COLD_TAIGA];

fn biome(cell: &mut Cell, value: i32) -> i32 {
    let special = (value & 0xf00) >> 8;
    let value = value & !0xf00;

    if biomes::is_oceanic(value) || value == id::MUSHROOM_ISLAND {
        return value;
    }

    match value {
        1 => {
            if special > 0 {
                if cell.next(3) == 0 { id::MESA_PLATEAU } else { id::MESA_PLATEAU_F }
            } else {
                WARM[cell.next(6) as usize]
            }
        }
        2 => {
            if special > 0 { id::JUNGLE } else { MEDIUM[cell.next(6) as usize] }
        }
        3 => {
            if special > 0 { id::MEGA_TAIGA } else { COLD[cell.next(4) as usize] }
        }
        4 => ICE[cell.next(4) as usize],
        _ => id::MUSHROOM_ISLAND,
    }
}

fn biome_edge(center: i32, up: i32, right: i32, left: i32, down: i32) -> i32 {
    let near = [up, right, left, down];

    for (base, edge) in [(id::MESA_PLATEAU_F, id::MESA), (id::MESA_PLATEAU, id::MESA), (id::MEGA_TAIGA, id::TAIGA)] {
        if center == base {
            return if near.iter().all(|&value| biomes::similar(value, base)) { center } else { edge };
        }
    }

    if center == id::DESERT {
        if near.contains(&id::ICE_PLAINS) { id::EXTREME_HILLS_PLUS } else { center }
    } else if center == id::SWAMPLAND {
        if near.iter().any(|&value| value == id::DESERT || value == id::COLD_TAIGA || value == id::ICE_PLAINS) {
            id::PLAINS
        } else if near.contains(&id::JUNGLE) {
            id::JUNGLE_EDGE
        } else {
            center
        }
    } else {
        center
    }
}

fn hills(cell: &mut Cell, center: i32, river: i32, near: &[i32; 4]) -> i32 {
    let mutate = (river - 2) % 29 == 0;

    if center != 0 && river >= 2 && (river - 2) % 29 == 1 && center < 128 {
        return if biomes::exists(center + 128) { center + 128 } else { center };
    }

    if cell.next(3) != 0 && !mutate {
        return center;
    }

    let mut hill = match center {
        id::DESERT => id::DESERT_HILLS,
        id::FOREST => id::FOREST_HILLS,
        id::BIRCH_FOREST => id::BIRCH_FOREST_HILLS,
        id::ROOFED_FOREST => id::PLAINS,
        id::TAIGA => id::TAIGA_HILLS,
        id::MEGA_TAIGA => id::MEGA_TAIGA_HILLS,
        id::COLD_TAIGA => id::COLD_TAIGA_HILLS,
        id::PLAINS => {
            if cell.next(3) == 0 { id::FOREST_HILLS } else { id::FOREST }
        }
        id::ICE_PLAINS => id::ICE_MOUNTAINS,
        id::JUNGLE => id::JUNGLE_HILLS,
        id::OCEAN => id::DEEP_OCEAN,
        id::EXTREME_HILLS => id::EXTREME_HILLS_PLUS,
        id::SAVANNA => id::SAVANNA_PLATEAU,
        _ if biomes::similar(center, id::MESA_PLATEAU_F) => id::MESA,
        id::DEEP_OCEAN if cell.next(3) == 0 => {
            if cell.next(2) == 0 { id::PLAINS } else { id::FOREST }
        }
        _ => center,
    };

    if mutate && hill != center {
        hill = if biomes::exists(hill + 128) { hill + 128 } else { center };
    }

    if hill == center {
        return center;
    }

    let alike = near.iter().filter(|&&value| biomes::similar(value, center)).count();

    if alike >= 3 { hill } else { center }
}

fn replace_ocean(center: i32, near: &[i32; 4], shore: i32) -> i32 {
    if near.iter().any(|&value| biomes::is_oceanic(value)) { shore } else { center }
}

fn shore(center: i32, up: i32, right: i32, left: i32, down: i32) -> i32 {
    let near = [up, right, left, down];

    if center == id::MUSHROOM_ISLAND {
        return if near.contains(&id::OCEAN) { id::MUSHROOM_ISLAND_SHORE } else { center };
    }

    if biomes::is_jungle(center) {
        let fits = |value: i32| biomes::is_jungle(value) || value == id::FOREST || value == id::TAIGA || biomes::is_oceanic(value);

        return if near.iter().all(|&value| fits(value)) {
            if near.iter().any(|&value| biomes::is_oceanic(value)) { id::BEACH } else { center }
        } else {
            id::JUNGLE_EDGE
        };
    }

    if matches!(center, id::EXTREME_HILLS | id::EXTREME_HILLS_PLUS | id::EXTREME_HILLS_EDGE) {
        return replace_ocean(center, &near, id::STONE_BEACH);
    }

    //frozen oceans are snowy too but never make it to this layer
    if biomes::get(center).is_some_and(|biome| biome.snowy) && !biomes::is_oceanic(center) {
        return replace_ocean(center, &near, id::COLD_BEACH);
    }

    if center == id::MESA || center == id::MESA_PLATEAU_F {
        if near.iter().any(|&value| biomes::is_oceanic(value)) {
            return center;
        }

        return if near.iter().all(|&value| biomes::is_mesa(value)) { center } else { id::DESERT };
    }

    if center != id::OCEAN && center != id::DEEP_OCEAN && center != id::RIVER && center != id::SWAMPLAND {
        replace_ocean(center, &near, id::BEACH)
    } else {
        center
    }
}

/// The 1.8 layer stack for a world seed.
pub struct Layers {
    /// one value per 4 x 4 blocks
    quarter: Arc<Layer>,
    /// one value per block
    full: Arc<Layer>,
}

impl Layers {
    pub fn new(seed: i64) -> Self {
        let world = Some(seed);
        let l = |kind: Kind, salt: i64, parent: Arc<Layer>| layer(kind, salt, world, Some(parent));

        let mut stack = layer(Kind::Island, 1, world, None);
        stack = l(Kind::FuzzyZoom, 2000, stack);
        stack = l(Kind::AddIsland, 1, stack);
        stack = l(Kind::Zoom, 2001, stack);
        stack = l(Kind::AddIsland, 2, stack);
        stack = l(Kind::AddIsland, 50, stack);
        stack = l(Kind::AddIsland, 70, stack);
        stack = l(Kind::RemoveTooMuchOcean, 2, stack);
        stack = l(Kind::AddSnow, 2, stack);
        stack = l(Kind::AddIsland, 3, stack);
        stack = l(Kind::CoolWarm, 2, stack);
        stack = l(Kind::HeatIce, 2, stack);
        stack = l(Kind::Special, 3, stack);
        stack = l(Kind::Zoom, 2002, stack);
        stack = l(Kind::Zoom, 2003, stack);
        stack = l(Kind::AddIsland, 4, stack);
        stack = l(Kind::AddMushroomIsland, 5, stack);
        let deep_ocean = l(Kind::DeepOcean, 4, stack);

        let river_init = l(Kind::RiverInit, 100, Arc::clone(&deep_ocean));
        let biome = l(Kind::Biome, 200, deep_ocean);
        let biome = magnify(1000, world, biome, 2);
        let edge = l(Kind::BiomeEdge, 1000, biome);

        //the game never seeds these two, it does not pass the seed on to the hills' second parent
        let hill_rivers = magnify(1000, None, Arc::clone(&river_init), 2);
        let mut hills = layer2(Kind::Hills, 1000, world, Some(edge), Some(hill_rivers));

        let rivers = magnify(1000, world, river_init, 2);
        let rivers = magnify(1000, world, rivers, 4);
        let rivers = l(Kind::River, 1, rivers);
        let rivers = l(Kind::Smooth, 1000, rivers);

        hills = l(Kind::RareBiome, 1001, hills);

        for i in 0..4 {
            hills = l(Kind::Zoom, 1000 + i, hills);

            if i == 0 {
                hills = l(Kind::AddIsland, 3, hills);
            }

            if i == 1 {
                hills = l(Kind::Shore, 1000, hills);
            }
        }

        let hills = l(Kind::Smooth, 1000, hills);
        let quarter = layer2(Kind::RiverMix, 100, world, Some(hills), Some(rivers));
        let full = l(Kind::Voronoi, 10, Arc::clone(&quarter));

        Layers { quarter, full }
    }

    /// Biome IDs, one per 4 x 4 blocks, laid out x then z: index x + z * w.
    pub fn quarter(&self, x: i32, z: i32, w: i32, h: i32) -> Vec<i32> {
        self.quarter.get(x, z, w, h)
    }

    /// Biome IDs, one per block, index x + z * w.
    pub fn full(&self, x: i32, z: i32, w: i32, h: i32) -> Vec<i32> {
        self.full.get(x, z, w, h)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn checksum(values: &[i32]) -> u64 {
        values.iter().fold(0u64, |hash, &value| hash.wrapping_mul(31).wrapping_add(value as i64 as u64))
    }

    /// The same areas from cubiomes set to 1.8.
    #[test]
    fn matches_cubiomes() {
        assert_eq!(checksum(&Layers::new(1).quarter(-1000, -1000, 300, 300)), 13710860883521909848);
        assert_eq!(checksum(&Layers::new(-4172144997902289642).full(1999, -3001, 200, 150)), 14097567623106759713);
        assert_eq!(checksum(&Layers::new(12345).full(-64, -64, 128, 128)), 10799057719073253690);
    }

    /// Prints an area of the map, to hold against another implementation:
    /// RX_LAYERS="seed scale x z w h" cargo test --release print_area -- --ignored --nocapture
    #[test]
    #[ignore]
    fn print_area() {
        let spec = std::env::var("RX_LAYERS").unwrap_or_else(|_| "12345 4 0 0 8 4".into());
        let values: Vec<i64> = spec.split_whitespace().map(|value| value.parse().unwrap()).collect();
        let layers = Layers::new(values[0]);
        let (x, z, w, h) = (values[2] as i32, values[3] as i32, values[4] as i32, values[5] as i32);
        let area = if values[1] == 4 { layers.quarter(x, z, w, h) } else { layers.full(x, z, w, h) };

        for row in area.chunks(w as usize) {
            println!("{} ", row.iter().map(|value| value.to_string()).collect::<Vec<_>>().join(" "));
        }
    }
}
