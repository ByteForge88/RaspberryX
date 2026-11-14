//! Explosions, the way the game works them out: rays go out from the center in every
//! direction, each weakening as it passes through blocks by how much they resist, and every
//! block a ray still has strength in breaks. Things standing near are hurt by how much of
//! them the center can see.

use std::collections::{HashMap, HashSet};
use std::sync::{Arc, RwLock};

use crate::chunk::{MAX_Y, MIN_Y};
use crate::random::JavaRandom;

const STEP: f64 = 0.3;
const STEP_COST: f32 = 0.225_000_01;

const GRID: i32 = 16;

#[derive(Clone, Copy, Default)]
pub struct Info {
    pub resistance: f32,
    pub solid: bool,
}

#[derive(Default)]
pub struct Table {
    info: HashMap<u32, Info>,
    air: u32,
}

static TABLE: RwLock<Option<Arc<Table>>> = RwLock::new(None);

pub fn learn(entries: &[(u32, Info)], air: u32) {
    let table = Table { info: entries.iter().copied().collect(), air };
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

fn floor(value: f64) -> i32 {
    value.floor() as i32
}

pub fn blocks<V: View>(view: &V, x: f64, y: f64, z: f64, radius: f32, seed: i64) -> Vec<(i32, i32, i32)> {
    let table = table();
    let mut random = JavaRandom::new(seed);
    let mut broken = HashSet::new();
    let mut order = Vec::new();
    let mut resistance: HashMap<(i32, i32, i32), Option<f32>> = HashMap::new();

    let mut resistance_at = |bx: i32, by: i32, bz: i32| -> Option<f32> {
        *resistance.entry((bx, by, bz)).or_insert_with(|| {
            let main = view.block(bx, by, bz, 0)?;
            let water = view.block(bx, by, bz, 1).unwrap_or(table.air);
            let of = |state: u32| if state == table.air { 0.0 } else { table.info.get(&state).map_or(6.0, |info| info.resistance) };

            Some(of(main).max(of(water)))
        })
    };

    for i in 0..GRID {
        for j in 0..GRID {
            for k in 0..GRID {
                if i != 0 && i != GRID - 1 && j != 0 && j != GRID - 1 && k != 0 && k != GRID - 1 {
                    continue;
                }

                let mut dx = i as f64 / (GRID - 1) as f64 * 2.0 - 1.0;
                let mut dy = j as f64 / (GRID - 1) as f64 * 2.0 - 1.0;
                let mut dz = k as f64 / (GRID - 1) as f64 * 2.0 - 1.0;
                let length = (dx * dx + dy * dy + dz * dz).sqrt();
                dx /= length;
                dy /= length;
                dz /= length;

                let mut strength = radius * (0.7 + random.next_float() * 0.6);
                let (mut rx, mut ry, mut rz) = (x, y, z);

                while strength > 0.0 {
                    let (bx, by, bz) = (floor(rx), floor(ry), floor(rz));

                    if !(MIN_Y..=MAX_Y).contains(&by) {
                        break;
                    }

                    let Some(resists) = resistance_at(bx, by, bz) else { break };
                    let air = view.block(bx, by, bz, 0) == Some(table.air) && resists == 0.0;

                    if !air {
                        strength -= (resists + 0.3) * 0.3;
                    }

                    if strength > 0.0 && !air && broken.insert((bx, by, bz)) {
                        order.push((bx, by, bz));
                    }

                    rx += dx * STEP;
                    ry += dy * STEP;
                    rz += dz * STEP;
                    strength -= STEP_COST;
                }
            }
        }
    }

    order
}

pub fn exposure<V: View>(view: &V, x: f64, y: f64, z: f64, min: [f64; 3], max: [f64; 3]) -> f32 {
    let table = table();
    let step_x = 1.0 / ((max[0] - min[0]) * 2.0 + 1.0);
    let step_y = 1.0 / ((max[1] - min[1]) * 2.0 + 1.0);
    let step_z = 1.0 / ((max[2] - min[2]) * 2.0 + 1.0);

    if step_x < 0.0 || step_y < 0.0 || step_z < 0.0 {
        return 0.0;
    }

    let offset_x = (1.0 - (1.0 / step_x).floor() * step_x) / 2.0;
    let offset_z = (1.0 - (1.0 / step_z).floor() * step_z) / 2.0;
    let solid = |bx: i32, by: i32, bz: i32| view.block(bx, by, bz, 0).is_some_and(|state| table.info.get(&state).is_some_and(|info| info.solid));

    let (mut seen, mut total) = (0, 0);
    let mut fx = 0.0;

    while fx <= 1.0 {
        let mut fy = 0.0;

        while fy <= 1.0 {
            let mut fz = 0.0;

            while fz <= 1.0 {
                let px = min[0] + (max[0] - min[0]) * fx + offset_x;
                let py = min[1] + (max[1] - min[1]) * fy;
                let pz = min[2] + (max[2] - min[2]) * fz + offset_z;

                if !line_blocked(px, py, pz, x, y, z, &solid) {
                    seen += 1;
                }

                total += 1;
                fz += step_z;
            }

            fy += step_y;
        }

        fx += step_x;
    }

    if total == 0 { 0.0 } else { seen as f32 / total as f32 }
}

fn line_blocked(x0: f64, y0: f64, z0: f64, x1: f64, y1: f64, z1: f64, solid: &impl Fn(i32, i32, i32) -> bool) -> bool {
    let (dx, dy, dz) = (x1 - x0, y1 - y0, z1 - z0);
    let (mut bx, mut by, mut bz) = (floor(x0), floor(y0), floor(z0));
    let (ex, ey, ez) = (floor(x1), floor(y1), floor(z1));

    let step = |d: f64| if d > 0.0 { 1 } else if d < 0.0 { -1 } else { 0 };
    let (sx, sy, sz) = (step(dx), step(dy), step(dz));

    let first = |start: f64, d: f64, b: i32| if d > 0.0 { ((b + 1) as f64 - start) / d } else if d < 0.0 { (start - b as f64) / -d } else { f64::INFINITY };
    let (mut tx, mut ty, mut tz) = (first(x0, dx, bx), first(y0, dy, by), first(z0, dz, bz));
    let gap = |d: f64| if d != 0.0 { 1.0 / d.abs() } else { f64::INFINITY };
    let (gx, gy, gz) = (gap(dx), gap(dy), gap(dz));

    for _ in 0..512 {
        if solid(bx, by, bz) {
            return true;
        }

        if (bx, by, bz) == (ex, ey, ez) {
            return false;
        }

        if tx <= ty && tx <= tz {
            if tx > 1.0 {
                return false;
            }

            bx += sx;
            tx += gx;
        } else if ty <= tz {
            if ty > 1.0 {
                return false;
            }

            by += sy;
            ty += gy;
        } else {
            if tz > 1.0 {
                return false;
            }

            bz += sz;
            tz += gz;
        }
    }

    false
}

#[cfg(test)]
mod tests {
    use super::*;

    const AIR: u32 = 1;
    const STONE: u32 = 2;
    const OBSIDIAN: u32 = 3;
    const WATER: u32 = 4;
    const DIRT: u32 = 5;

    fn teach() {
        learn(
            &[
                (STONE, Info { resistance: 6.0, solid: true }),
                (OBSIDIAN, Info { resistance: 1200.0, solid: true }),
                (WATER, Info { resistance: 100.0, solid: false }),
                (DIRT, Info { resistance: 0.5, solid: true }),
            ],
            AIR,
        );
    }

    struct Map(HashMap<(i32, i32, i32), u32>);

    impl View for Map {
        fn block(&self, x: i32, y: i32, z: i32, layer: usize) -> Option<u32> {
            if layer > 0 {
                return Some(AIR);
            }

            Some(*self.0.get(&(x, y, z)).unwrap_or(&if y < 0 { DIRT } else { AIR }))
        }
    }

    #[test]
    fn tnt_on_dirt_makes_a_crater() {
        teach();
        let map = Map(HashMap::new());
        let broken = blocks(&map, 0.5, 0.0625, 0.5, 4.0, 1);

        assert!(broken.len() > 20 && broken.len() < 200, "{} blocks", broken.len());
        assert!(broken.contains(&(0, -1, 0)));
        assert!(broken.iter().all(|&(x, y, z)| y < 0 && x.abs() <= 4 && z.abs() <= 4));
    }

    #[test]
    fn obsidian_holds_and_water_breaks_nothing() {
        teach();
        let mut map = Map(HashMap::new());

        for x in -6..=6 {
            for z in -6..=6 {
                map.0.insert((x, -1, z), OBSIDIAN);
            }
        }

        assert!(blocks(&map, 0.5, 0.0625, 0.5, 4.0, 1).is_empty());

        let mut map = Map(HashMap::new());
        map.0.insert((0, 0, 0), WATER);
        assert!(blocks(&map, 0.5, 0.0625, 0.5, 4.0, 1).iter().all(|&(_, y, _)| y >= 0));
    }

    #[test]
    fn a_wall_hides_what_is_behind_it() {
        teach();
        let mut map = Map(HashMap::new());

        for y in 0..5 {
            for z in -3..=3 {
                map.0.insert((2, y, z), STONE);
            }
        }

        let open = exposure(&map, 0.5, 0.5, 0.5, [-3.3, 0.0, 0.2], [-2.7, 1.8, 0.8]);
        let hidden = exposure(&map, 0.5, 0.5, 0.5, [3.2, 0.0, 0.2], [3.8, 1.8, 0.8]);

        assert!(open > 0.99, "open {open}");
        assert!(hidden < 0.01, "hidden {hidden}");
    }
}