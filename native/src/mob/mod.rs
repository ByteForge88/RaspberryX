//! Mobs. PHP keeps what a mob is (its health, what it drops, what feeding it does). Where it
//! walks and why is decided here: goals pick what a mob wants, pathfinding finds the way and
//! the physics move it.
//!
//! A world's mobs are ticked together on a worker thread. PHP hands over what changed (new
//! mobs, hits, where the players are) and gets back who moved and what happened, one tick
//! later, so a crowd of mobs never holds up the server's own tick.

pub mod blocks;
pub mod goals;
pub mod path;
pub mod physics;

use std::collections::{HashMap, HashSet};

use crate::random::JavaRandom;

use blocks::View;
use goals::Goal;
use path::{Node, Walker};

pub const BABY: u32 = 1;
pub const IN_LOVE: u32 = 2;
pub const SLOW_FALL: u32 = 4;
pub const NO_AI: u32 = 8;
pub const ON_FIRE: u32 = 16;
pub const AVOID_WATER: u32 = 32;
pub const BRIGHT: u32 = 64;
pub const CLIMBER: u32 = 128;

pub const ON_GROUND: u32 = 1;
pub const IN_WATER: u32 = 2;
pub const HEAD_IN_WATER: u32 = 4;
pub const IN_LAVA: u32 = 8;
pub const HAZARD: u32 = 16;
pub const IN_FIRE: u32 = 32;
pub const CLIMBING: u32 = 64;

pub const PLAYER_SPECTATOR: u32 = 1;
pub const PLAYER_NO_TARGET: u32 = 2;
pub const PLAYER_SNEAKING: u32 = 4;
pub const PLAYER_INVISIBLE: u32 = 8;

pub const EVENT_FALL: u32 = 1;
pub const EVENT_EAT_START: u32 = 2;
pub const EVENT_EAT_BLOCK: u32 = 3;
pub const EVENT_BRED: u32 = 4;
pub const EVENT_LAND: u32 = 5;
pub const EVENT_ATTACK: u32 = 6;
pub const EVENT_TARGET: u32 = 7;
pub const EVENT_SWELL: u32 = 8;

const HURT_MEMORY: i32 = 100;

const WATER_DRAG: f64 = 0.8;
const LAVA_DRAG: f64 = 0.5;
const AIR_DRAG: f64 = 0.91;
const GROUND_DRAG: f64 = 0.6 * 0.91;

const PATH_RANGE: f32 = 24.0;
const PATH_BUDGET: usize = 320;

#[derive(Default)]
pub struct Nav {
    pub path: Vec<Node>,
    pub index: usize,
    pub speed: f32,
    stuck: i32,
    last: [f64; 3],
}

impl Nav {
    pub fn is_done(&self) -> bool {
        self.index >= self.path.len()
    }

    pub fn stop(&mut self) {
        self.path.clear();
        self.index = 0;
    }
}

pub struct Mob {
    pub id: u64,
    pub kind: u32,
    pub x: f64,
    pub y: f64,
    pub z: f64,
    pub mx: f64,
    pub my: f64,
    pub mz: f64,
    pub yaw: f32,
    pub head_yaw: f32,
    pub pitch: f32,
    pub width: f64,
    pub height: f64,
    pub speed: f32,
    pub flags: u32,
    pub state: u32,
    pub hit_wall: bool,
    pub fall: f64,
    pub hurt: i32,
    pub jump_delay: i32,
    pub target: u64,
    pub hurt_by: u64,
    pub hurt_count: u32,
    pub goals: Vec<Goal>,
    pub nav: Nav,
    pub look: Option<[f64; 3]>,
    pub jump: bool,
    sent: Option<([f64; 3], [f32; 3], u32)>,
}

impl Mob {
    pub fn has(&self, flag: u32) -> bool {
        self.flags & flag != 0
    }

    pub fn is(&self, state: u32) -> bool {
        self.state & state != 0
    }

    pub fn eye_height(&self) -> f64 {
        self.height * 0.85
    }

    pub fn walker(&self) -> Walker {
        Walker::new(self.height, self.has(AVOID_WATER))
    }

    pub fn distance_sq(&self, x: f64, y: f64, z: f64) -> f64 {
        (self.x - x) * (self.x - x) + (self.y - y) * (self.y - y) + (self.z - z) * (self.z - z)
    }

    pub fn move_to(&mut self, view: &View, x: f64, y: f64, z: f64, speed: f32) -> bool {
        let walker = self.walker();
        let start = walker.start(view, self.x, self.y, self.z);
        let goal = [x.floor() as i32, (y + 0.5).floor() as i32, z.floor() as i32];

        self.nav.path = path::find(view, walker, start, goal, PATH_RANGE, PATH_BUDGET);

        if self.has(CLIMBER) && self.nav.path.last() != Some(&goal) {
            self.nav.path.push(goal);
        }

        self.nav.index = 0;
        self.nav.speed = speed;
        self.nav.stuck = 0;
        self.nav.last = [self.x, self.y, self.z];

        !self.nav.path.is_empty()
    }

    fn update_state(&mut self, view: &View) {
        let (bx, bz) = (self.x.floor() as i32, self.z.floor() as i32);
        let feet = view.block(bx, (self.y + 0.05).floor() as i32, bz);
        let body = view.block(bx, (self.y + 0.4).floor() as i32, bz);
        let below = view.block(bx, (self.y - 0.1).floor() as i32, bz);
        let head = view.block(bx, (self.y + self.eye_height()).floor() as i32, bz);
        let mut state = self.state & (ON_GROUND | CLIMBING);

        if (feet | body) & blocks::WATER != 0 {
            state |= IN_WATER;
        }

        if head & blocks::WATER != 0 {
            state |= HEAD_IN_WATER;
        }

        if (feet | body) & blocks::LAVA != 0 {
            state |= IN_LAVA;
        }

        if feet & blocks::FIRE != 0 {
            state |= IN_FIRE;
        }

        let mut hazard = (feet | below) & blocks::DAMAGE != 0;

        if !hazard && self.hit_wall {
            let y = (self.y + 0.05).floor() as i32;
            let reach = self.width / 2.0 + 0.1;

            hazard = [(reach, 0.0), (-reach, 0.0), (0.0, reach), (0.0, -reach)].into_iter().any(|(dx, dz)| view.block((self.x + dx).floor() as i32, y, (self.z + dz).floor() as i32) & blocks::DAMAGE != 0);
        }

        if hazard {
            state |= HAZARD;
        }

        self.state = state;
    }

    fn follow_path(&mut self, view: &View) -> f32 {
        let reach = if self.width > 0.75 { self.width / 2.0 } else { 0.75 - self.width / 2.0 };

        while let Some(node) = self.nav.path.get(self.nav.index).copied() {
            let (tx, tz) = (node[0] as f64 + 0.5, node[2] as f64 + 0.5);
            let floor = blocks::height(view.block(node[0], node[1] - 1, node[2])).min(1.0);
            let ty = if floor > 0.0 { node[1] as f64 - 1.0 + floor } else { node[1] as f64 };
            let (dx, dy, dz) = (tx - self.x, ty - self.y, tz - self.z);
            let flat = dx * dx + dz * dz;

            if flat < reach * reach && dy.abs() < 1.0 {
                self.nav.index += 1;
                continue;
            }

            self.nav.stuck += 1;

            if self.nav.stuck >= 100 {
                let [lx, ly, lz] = self.nav.last;
                let speed = (self.speed * self.nav.speed) as f64;
                let least = speed * speed * 25.0;

                if self.distance_sq(lx, ly, lz) < least * least {
                    self.nav.stop();
                    return 0.0;
                }

                self.nav.stuck = 0;
                self.nav.last = [self.x, self.y, self.z];
            }

            if flat > 2.5e-7 {
                let wanted = (dz.atan2(dx).to_degrees() - 90.0) as f32;
                self.yaw = turn(self.yaw, wanted, 90.0);
            }

            if dy > physics::STEP_HEIGHT && flat < self.width.max(1.0) {
                self.jump = true;
            }

            return self.speed * self.nav.speed;
        }

        0.0
    }

    fn turn_head(&mut self, moving: bool) {
        match self.look {
            Some([x, y, z]) => {
                let (dx, dy, dz) = (x - self.x, y - (self.y + self.eye_height()), z - self.z);
                let flat = (dx * dx + dz * dz).sqrt();

                self.head_yaw = turn(self.head_yaw, (dz.atan2(dx).to_degrees() - 90.0) as f32, 10.0);
                self.pitch = turn(self.pitch, -(dy.atan2(flat).to_degrees()) as f32, 40.0);
            }
            None => {
                self.head_yaw = turn(self.head_yaw, self.yaw, 10.0);
                self.pitch = turn(self.pitch, 0.0, 40.0);
            }
        }

        let off = wrap(self.head_yaw - self.yaw);

        if moving {
            self.head_yaw = self.yaw + off.clamp(-75.0, 75.0);
        } else if off.abs() > 50.0 {
            self.yaw = turn(self.yaw, self.head_yaw, 10.0);
        }
    }

        fn stuck_in(&self, view: &View) -> Option<[f64; 3]> {
            let (bx, bz) = (self.x.floor() as i32, self.z.floor() as i32);
            (self.y.floor() as i32..=(self.y + self.height - 0.01).floor() as i32).find_map(|y| {
                let flags = view.block(bx, y, bz);

                if self.has(CLIMBER) && blocks::kind(flags) == blocks::COBWEB {
                    None
                } else {
                    blocks::stuck(flags)
                }
            })
        }

        fn travel(&mut self, view: &View, forward: f32, events: &mut Vec<Event>) {
            let on_ground = self.is(ON_GROUND);
            let in_liquid = self.is(IN_WATER | IN_LAVA);
            let surface = view.block(self.x.floor() as i32, (self.y - 0.5000001).floor() as i32, self.z.floor() as i32);
            let stuck = self.stuck_in(view);

        if self.jump_delay > 0 {
            self.jump_delay -= 1;
        }

        if self.jump {
            if in_liquid {
                self.my += 0.04;
            } else if on_ground && self.jump_delay == 0 {
                self.my = if blocks::kind(surface) == blocks::HONEY { physics::JUMP * 0.5 } else { physics::JUMP };
                self.jump_delay = 10;
            }
        }

        if forward > 0.0 {
            let push = if on_ground && !in_liquid { forward as f64 * (0.16277136 / (GROUND_DRAG * GROUND_DRAG * GROUND_DRAG)) } else { 0.02 };
            let radians = (self.yaw as f64).to_radians();

            self.mx -= radians.sin() * push * forward as f64;
            self.mz += radians.cos() * push * forward as f64;
        }

        if self.has(SLOW_FALL) && !on_ground && self.my < 0.0 {
            self.my *= 0.6;
        }

        let climbing = self.is(CLIMBING);

        if climbing {
            self.mx = self.mx.clamp(-0.15, 0.15);
            self.mz = self.mz.clamp(-0.15, 0.15);
            self.my = self.my.max(-0.15);
            self.fall = 0.0;
        }

        let [sx, sy, sz] = stuck.unwrap_or([1.0; 3]);
        let falling = self.my;
        let moved = physics::move_box(view, self.x, self.y, self.z, self.width, self.height, self.mx * sx, self.my * sy, self.mz * sz, on_ground);

        if moved.y < self.y && !in_liquid {
            self.fall += self.y - moved.y;
        }

                if in_liquid || stuck.is_some() || climbing {
                    self.fall = 0.0;
                }

                if stuck.is_some() {
                    self.mx = 0.0;
                    self.my = 0.0;
                    self.mz = 0.0;
                }

                let landed_on = view.block(moved.x.floor() as i32, (moved.y - 0.2).floor() as i32, moved.z.floor() as i32);

                if moved.on_ground {
                    let damage = (self.fall - 3.0) * blocks::fall_multiplier(landed_on);

                    if damage > 0.0 {
                        events.push(Event { id: self.id, kind: EVENT_FALL, value: (3.0 + damage) as f32, block: [0; 3], other: 0 });
                    }

                    if self.fall > 0.5 && landed_on & (blocks::HEIGHT | blocks::KIND) == 15 {
                        let block = [moved.x.floor() as i32, (moved.y - 0.2).floor() as i32, moved.z.floor() as i32];
                        events.push(Event { id: self.id, kind: EVENT_LAND, value: self.fall as f32, block, other: 0 });
                    }

            self.fall = 0.0;
        }

        self.x = moved.x;
        self.y = moved.y;
        self.z = moved.z;
        self.hit_wall = moved.hit_x || moved.hit_z;

        if moved.hit_x {
            self.mx = 0.0;
        }

        if moved.hit_y {
            self.my = 0.0;
        }

        if moved.on_ground && falling < 0.0 && stuck.is_none() {
            if blocks::kind(landed_on) == blocks::SLIME {
                self.my = -falling;
            } else if landed_on & blocks::BED != 0 {
                self.my = -falling * 0.66;
            }
        }

        match blocks::kind(surface) {
            blocks::SOUL_SAND | blocks::HONEY => {
                self.mx *= 0.4;
                self.mz *= 0.4;
            }
            blocks::SLIME if on_ground && falling.abs() < 0.1 => {
                let slow = 0.4 + falling.abs() * 0.2;
                self.mx *= slow;
                self.mz *= slow;
            }
            _ => {}
        }

        if moved.hit_z {
            self.mz = 0.0;
        }

        self.state = (self.state & !(ON_GROUND | CLIMBING)) | if moved.on_ground { ON_GROUND } else { 0 };

        if self.has(CLIMBER) && self.hit_wall {
            self.state |= CLIMBING;
            self.my = 0.2;
        }

        if in_liquid {
            let drag = if self.is(IN_WATER) { WATER_DRAG } else { LAVA_DRAG };

            self.mx *= drag;
            self.my *= drag;
            self.mz *= drag;
            self.my -= 0.02;

            if self.hit_wall && physics::is_free(view, self.x + self.mx, self.y + self.my + 0.6, self.z + self.mz, self.width, self.height) {
                self.my = 0.3;
            }
        } else {
            let drag = if on_ground { GROUND_DRAG } else { AIR_DRAG };

            self.my = (self.my - physics::GRAVITY) * 0.98;
            self.mx *= drag;
            self.mz *= drag;
        }

        if self.mx.abs() < 0.003 {
            self.mx = 0.0;
        }

        if self.mz.abs() < 0.003 {
            self.mz = 0.0;
        }
    }
}

pub fn turn(from: f32, to: f32, step: f32) -> f32 {
    from + wrap(to - from).clamp(-step, step)
}

pub fn wrap(degrees: f32) -> f32 {
    let wrapped = degrees % 360.0;

    if wrapped >= 180.0 {
        wrapped - 360.0
    } else if wrapped < -180.0 {
        wrapped + 360.0
    } else {
        wrapped
    }
}

pub struct Player {
    pub id: u64,
    pub x: f64,
    pub y: f64,
    pub z: f64,
    pub eye: f64,
    pub flags: u32,
    pub tempt: u64,
}

pub struct Info {
    pub id: u64,
    pub kind: u32,
    pub x: f64,
    pub y: f64,
    pub z: f64,
    pub width: f64,
    pub height: f64,
    pub flags: u32,
}

pub struct Event {
    pub id: u64,
    pub kind: u32,
    pub value: f32,
    pub block: [i32; 3],
    pub other: u64,
}

pub struct Grid {
    cells: HashMap<(i32, i32), Vec<u32>>,
}

impl Grid {
    const CELL: f64 = 8.0;

    fn new(infos: &[Info]) -> Self {
        let mut cells: HashMap<(i32, i32), Vec<u32>> = HashMap::new();

        for (index, info) in infos.iter().enumerate() {
            cells.entry(((info.x / Self::CELL).floor() as i32, (info.z / Self::CELL).floor() as i32)).or_default().push(index as u32);
        }

        Grid { cells }
    }

    pub fn near(&self, x: f64, z: f64, radius: f64, mut found: impl FnMut(usize)) {
        let (min_x, max_x) = (((x - radius) / Self::CELL).floor() as i32, ((x + radius) / Self::CELL).floor() as i32);
        let (min_z, max_z) = (((z - radius) / Self::CELL).floor() as i32, ((z + radius) / Self::CELL).floor() as i32);

        for cx in min_x..=max_x {
            for cz in min_z..=max_z {
                if let Some(cell) = self.cells.get(&(cx, cz)) {
                    cell.iter().for_each(|index| found(*index as usize));
                }
            }
        }
    }
}

pub struct World {
    mobs: Vec<Mob>,
    index: HashMap<u64, usize>,
    players: Vec<Player>,
    random: JavaRandom,
    tick: u64,
    ticking: Option<HashSet<(i32, i32)>>,
}

struct Reader<'a> {
    data: &'a [u8],
    offset: usize,
}

impl Reader<'_> {
    fn bytes<const N: usize>(&mut self) -> Option<[u8; N]> {
        let bytes = self.data.get(self.offset..self.offset + N)?.try_into().ok()?;
        self.offset += N;
        Some(bytes)
    }

    fn u8(&mut self) -> Option<u8> {
        self.bytes::<1>().map(|bytes| bytes[0])
    }

    fn u32(&mut self) -> Option<u32> {
        self.bytes().map(u32::from_le_bytes)
    }

    fn u64(&mut self) -> Option<u64> {
        self.bytes().map(u64::from_le_bytes)
    }

    fn f32(&mut self) -> Option<f32> {
        self.bytes().map(f32::from_le_bytes)
    }

    fn f64(&mut self) -> Option<f64> {
        self.bytes().map(f64::from_le_bytes)
    }
}

const COMMAND_ADD: u8 = 1;
const COMMAND_REMOVE: u8 = 2;
const COMMAND_UPDATE: u8 = 3;
const COMMAND_TELEPORT: u8 = 4;
const COMMAND_MOTION: u8 = 5;
const COMMAND_HURT: u8 = 6;
const COMMAND_PLAYERS: u8 = 7;
const COMMAND_TICKING: u8 = 8;

impl World {
    pub fn new(seed: i64) -> Self {
        World { mobs: Vec::new(), index: HashMap::new(), players: Vec::new(), random: JavaRandom::new(seed), tick: 0, ticking: None }
    }

    fn is_ticking(&self, mob: &Mob) -> bool {
        self.ticking.as_ref().map_or(true, |ticking| ticking.contains(&((mob.x.floor() as i32) >> 4, (mob.z.floor() as i32) >> 4)))
    }

    fn mob(&mut self, id: u64) -> Option<&mut Mob> {
        self.index.get(&id).map(|index| &mut self.mobs[*index])
    }

    fn remove(&mut self, id: u64) {
        if let Some(index) = self.index.remove(&id) {
            self.mobs.swap_remove(index);

            if let Some(moved) = self.mobs.get(index) {
                self.index.insert(moved.id, index);
            }
        }
    }

    pub fn apply(&mut self, input: &[u8]) -> Option<()> {
        let mut reader = Reader { data: input, offset: 0 };

        while reader.offset < input.len() {
            match reader.u8()? {
                COMMAND_ADD => {
                    let (id, kind) = (reader.u64()?, reader.u32()?);
                    let (x, y, z) = (reader.f64()?, reader.f64()?, reader.f64()?);
                    let yaw = reader.f32()?;
                    let (width, height, speed) = (reader.f32()?, reader.f32()?, reader.f32()?);
                    let flags = reader.u32()?;
                    let mut goals = Vec::new();

                    for _ in 0..reader.u8()? {
                        let (kind, priority) = (reader.u8()?, reader.u8()?);
                        let params = [reader.f32()?, reader.f32()?, reader.f32()?, reader.f32()?];

                        goals.push(Goal::new(kind, priority, params));
                    }

                    goals.sort_by_key(|goal| goal.priority);
                    self.remove(id);
                    self.index.insert(id, self.mobs.len());
                    self.mobs.push(Mob {
                        id,
                        kind,
                        x,
                        y,
                        z,
                        mx: 0.0,
                        my: 0.0,
                        mz: 0.0,
                        yaw,
                        head_yaw: yaw,
                        pitch: 0.0,
                        width: width as f64,
                        height: height as f64,
                        speed,
                        flags,
                        state: 0,
                        hit_wall: false,
                        fall: 0.0,
                        hurt: 0,
                        jump_delay: 0,
                        target: 0,
                        hurt_by: 0,
                        hurt_count: 0,
                        goals,
                        nav: Nav::default(),
                        look: None,
                        jump: false,
                        sent: None,
                    });
                }
                COMMAND_REMOVE => {
                    let id = reader.u64()?;
                    self.remove(id);
                }
                COMMAND_UPDATE => {
                    let id = reader.u64()?;
                    let (width, height, speed, flags) = (reader.f32()?, reader.f32()?, reader.f32()?, reader.u32()?);

                    if let Some(mob) = self.mob(id) {
                        mob.width = width as f64;
                        mob.height = height as f64;
                        mob.speed = speed;
                        mob.flags = flags;
                    }
                }
                COMMAND_TELEPORT => {
                    let id = reader.u64()?;
                    let (x, y, z, yaw) = (reader.f64()?, reader.f64()?, reader.f64()?, reader.f32()?);

                    if let Some(mob) = self.mob(id) {
                        (mob.x, mob.y, mob.z) = (x, y, z);
                        (mob.yaw, mob.head_yaw) = (yaw, yaw);
                        (mob.mx, mob.my, mob.mz) = (0.0, 0.0, 0.0);
                        mob.fall = 0.0;
                        mob.nav.stop();
                    }
                }
                COMMAND_MOTION => {
                    let id = reader.u64()?;
                    let (mx, my, mz) = (reader.f32()?, reader.f32()?, reader.f32()?);

                    if let Some(mob) = self.mob(id) {
                        (mob.mx, mob.my, mob.mz) = (mx as f64, my as f64, mz as f64);
                    }
                }
                COMMAND_HURT => {
                    let (id, attacker) = (reader.u64()?, reader.u64()?);

                    if let Some(mob) = self.mob(id) {
                        mob.hurt = HURT_MEMORY;

                        if attacker != 0 {
                            mob.hurt_by = attacker;
                            mob.hurt_count = mob.hurt_count.wrapping_add(1);
                        }
                    }
                }
                COMMAND_PLAYERS => {
                    self.players.clear();

                    for _ in 0..reader.u32()? {
                        self.players.push(Player {
                            id: reader.u64()?,
                            x: reader.f64()?,
                            y: reader.f64()?,
                            z: reader.f64()?,
                            eye: reader.f32()? as f64,
                            flags: reader.u32()?,
                            tempt: reader.u64()?,
                        });
                    }
                }
                COMMAND_TICKING => {
                    let mut ticking = HashSet::new();

                    for _ in 0..reader.u32()? {
                        ticking.insert((reader.u32()? as i32, reader.u32()? as i32));
                    }

                    self.ticking = Some(ticking);
                }
                _ => return None,
            }
        }

        Some(())
    }

    pub fn chunks_needed(&self) -> Vec<(i32, i32)> {
        let mut needed: Vec<(i32, i32)> = Vec::new();

        for mob in self.mobs.iter().filter(|mob| self.is_ticking(mob)) {
            let (cx, cz) = ((mob.x.floor() as i32) >> 4, (mob.z.floor() as i32) >> 4);

            for x in cx - 1..=cx + 1 {
                for z in cz - 1..=cz + 1 {
                    needed.push((x, z));
                }
            }
        }

        needed.sort_unstable();
        needed.dedup();
        needed
    }

    pub fn tick(&mut self, view: &View) -> Vec<u8> {
        self.tick += 1;

        let infos: Vec<Info> = self.mobs.iter().map(|mob| Info { id: mob.id, kind: mob.kind, x: mob.x, y: mob.y, z: mob.z, width: mob.width, height: mob.height, flags: mob.flags }).collect();
        let grid = Grid::new(&infos);
        let mut events = Vec::new();
        let mut out_of_love = Vec::new();
        let mut moves = Vec::new();
        let ticking = &self.ticking;

        for mob in &mut self.mobs {
            if !view.is_loaded(mob.x.floor() as i32, mob.z.floor() as i32) {
                continue;
            }

            if ticking.as_ref().is_some_and(|ticking| !ticking.contains(&((mob.x.floor() as i32) >> 4, (mob.z.floor() as i32) >> 4))) {
                continue;
            }

            mob.update_state(view);
            mob.look = None;
            mob.jump = false;

            if mob.hurt > 0 {
                mob.hurt -= 1;
            }

            let mut forward = 0.0;

            if !mob.has(NO_AI) {
                let mut context = goals::Context { view, infos: &infos, index: &self.index, grid: &grid, players: &self.players, random: &mut self.random, events: &mut events, out_of_love: &mut out_of_love };

                goals::tick(mob, &mut context);
                forward = mob.follow_path(view);
                mob.turn_head(forward > 0.0);
            }

            let (mut push_x, mut push_z) = (0.0, 0.0);
            let mut push = |x: f64, y: f64, z: f64, width: f64, height: f64| {
                let (dx, dz) = (x - mob.x, z - mob.z);
                let reach = (width + mob.width) / 2.0;

                if dx.abs() >= reach || dz.abs() >= reach || y >= mob.y + mob.height || y + height <= mob.y {
                    return;
                }

                let apart = dx.abs().max(dz.abs());

                if apart >= 0.01 {
                    let apart = apart.sqrt();
                    let scale = 0.05 * (1.0 / apart).min(1.0) / apart;

                    push_x -= dx * scale;
                    push_z -= dz * scale;
                }
            };

            grid.near(mob.x, mob.z, 2.0, |index| {
                let other = &infos[index];

                if other.id != mob.id {
                    push(other.x, other.y, other.z, other.width, other.height);
                }
            });

            for player in &self.players {
                if player.flags & PLAYER_SPECTATOR == 0 {
                    push(player.x, player.y, player.z, 0.6, 1.8);
                }
            }

            mob.mx += push_x;
            mob.mz += push_z;

            mob.travel(view, forward, &mut events);

            let now = ([mob.x, mob.y, mob.z], [mob.yaw, mob.head_yaw, mob.pitch], mob.state);

            if mob.sent != Some(now) {
                mob.sent = Some(now);
                moves.push(mob.id);
            }
        }

        for id in out_of_love {
            if let Some(mob) = self.mob(id) {
                mob.flags &= !IN_LOVE;
            }
        }

        let unknown = view.take_unknown();
        let mut out = Vec::with_capacity(12 + moves.len() * 48 + events.len() * 36 + unknown.len() * 4);

        out.extend_from_slice(&(moves.len() as u32).to_le_bytes());

        for id in moves {
            let mob = &self.mobs[self.index[&id]];

            out.extend_from_slice(&mob.id.to_le_bytes());
            out.extend_from_slice(&mob.x.to_le_bytes());
            out.extend_from_slice(&mob.y.to_le_bytes());
            out.extend_from_slice(&mob.z.to_le_bytes());
            out.extend_from_slice(&mob.yaw.to_le_bytes());
            out.extend_from_slice(&mob.head_yaw.to_le_bytes());
            out.extend_from_slice(&mob.pitch.to_le_bytes());
            out.extend_from_slice(&mob.state.to_le_bytes());
        }

        out.extend_from_slice(&(events.len() as u32).to_le_bytes());

        for event in events {
            out.extend_from_slice(&event.id.to_le_bytes());
            out.extend_from_slice(&event.kind.to_le_bytes());
            out.extend_from_slice(&event.value.to_le_bytes());

            for part in event.block {
                out.extend_from_slice(&part.to_le_bytes());
            }

            out.extend_from_slice(&event.other.to_le_bytes());
        }

        out.extend_from_slice(&(unknown.len() as u32).to_le_bytes());

        for state in unknown {
            out.extend_from_slice(&state.to_le_bytes());
        }

        out
    }
}