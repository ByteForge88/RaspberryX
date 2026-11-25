//! What a mob wants to do. Every mob has a list of goals with priorities, the way the game's
//! own mobs do: a goal that can start takes over from less important ones that use the same
//! part of the mob (its legs, its head, its jumping), and runs until it is done.

use std::collections::HashMap;

use crate::random::JavaRandom;

use super::blocks::{self, View};
use super::path::Node;
use super::{turn, Event, Grid, Info, Mob, Player, AQUATIC, BABY, BRIGHT, CALM, DAYTIME, EVENT_ATTACK, EVENT_SHOOT, EVENT_SWELL, EVENT_USE_ITEM, ON_GROUND, EVENT_BRED, EVENT_EAT_BLOCK, EVENT_EAT_START, EVENT_TARGET, IN_LAVA, IN_LOVE, IN_WATER, ON_FIRE, PLAYER_INVISIBLE, PLAYER_NO_TARGET, PLAYER_SNEAKING, PLAYER_SPECTATOR};

pub const FLOAT: u8 = 1;
pub const PANIC: u8 = 2;
pub const BREED: u8 = 3;
pub const TEMPT: u8 = 4;
pub const FOLLOW_PARENT: u8 = 5;
pub const RANDOM_STROLL: u8 = 6;
pub const LOOK_AT_PLAYER: u8 = 7;
pub const RANDOM_LOOK_AROUND: u8 = 8;
pub const EAT_BLOCK: u8 = 9;
pub const NEAREST_ATTACKABLE_TARGET: u8 = 10;
pub const HURT_BY_TARGET: u8 = 11;
pub const MELEE_ATTACK: u8 = 12;
pub const LEAP_AT_TARGET: u8 = 13;
pub const SWELL: u8 = 14;
pub const RANGED_BOW_ATTACK: u8 = 15;
pub const RANGED_ATTACK: u8 = 16;
pub const SLIME_ATTACK: u8 = 17;
pub const SLIME_RANDOM_DIRECTION: u8 = 18;

const ONLY_IN_DARK: f32 = 1.0;
const IN_WATER_BY_DAY: f32 = 2.0;

const MOVE: u8 = 1;
const LOOK: u8 = 2;
const JUMP: u8 = 4;
const TARGET: u8 = 8;

const UNSEEN_MEMORY: i32 = 60;

const ATTACK_REACH: f64 = 0.828_427_124_746_190_1;

const ATTACK_COOLDOWN: i32 = 20;

#[derive(Clone, Copy)]
pub struct Target {
    pub id: u64,
    pub x: f64,
    pub y: f64,
    pub z: f64,
    pub width: f64,
    pub height: f64,
    pub eye: f64,
    pub flags: u32,
}

impl Target {
    fn of(player: &Player) -> Self {
        let height = if player.flags & PLAYER_SNEAKING != 0 { 1.5 } else { 1.8 };

        Target { id: player.id, x: player.x, y: player.y, z: player.z, width: 0.6, height, eye: player.eye, flags: player.flags }
    }

    fn of_mob(info: &Info) -> Self {
        Target { id: info.id, x: info.x, y: info.y, z: info.z, width: info.width, height: info.height, eye: info.height * 0.85, flags: 0 }
    }

    fn visibility(&self) -> f64 {
        let mut visibility = 1.0;

        if self.flags & PLAYER_SNEAKING != 0 {
            visibility *= 0.8;
        }

        if self.flags & PLAYER_INVISIBLE != 0 {
            visibility *= 0.07;
        }

        visibility
    }
}

pub struct Goal {
    pub kind: u8,
    pub priority: u8,
    pub params: [f32; 6],
    running: bool,
    timer: i32,
    count: i32,
    delay: i32,
    charge: i32,
    strafe: (bool, bool),
    target: u64,
    spot: [f64; 3],
}

pub struct Context<'a> {
    pub view: &'a View,
    pub infos: &'a [Info],
    pub index: &'a HashMap<u64, usize>,
    pub grid: &'a Grid,
    pub players: &'a [Player],
    pub random: &'a mut JavaRandom,
    pub events: &'a mut Vec<Event>,
    pub out_of_love: &'a mut Vec<u64>,
}

impl Context<'_> {
    fn chance(&mut self, chance: f32) -> bool {
        self.random.next_float() < chance
    }

    fn info(&self, id: u64) -> Option<&Info> {
        self.index.get(&id).and_then(|index| self.infos.get(*index))
    }

    fn player(&self, id: u64) -> Option<&Player> {
        self.players.iter().find(|player| player.id == id && player.flags & PLAYER_SPECTATOR == 0)
    }

    fn target(&self, id: u64) -> Option<Target> {
        if id == 0 {
            return None;
        }

        match self.players.iter().find(|player| player.id == id) {
            Some(player) if player.flags & (PLAYER_SPECTATOR | PLAYER_NO_TARGET) == 0 => Some(Target::of(player)),
            Some(_) => None,
            None => self.index.get(&id).map(|&index| Target::of_mob(&self.infos[index])),
        }
    }

    fn is_player(&self, id: u64) -> bool {
        self.players.iter().any(|player| player.id == id)
    }

    fn nearest_mob(&self, mob: &Mob, kind: u32, range: f64, must_see: bool) -> Option<Target> {
        let mut best: Option<(f64, Target)> = None;

        self.grid.near(mob.x, mob.z, range, |index| {
            let other = &self.infos[index];

            if other.id == mob.id || other.kind != kind || (other.y - mob.y).abs() > 4.0 + mob.height {
                return;
            }

            let distance = mob.distance_sq(other.x, other.y, other.z);

            if distance > range * range || best.as_ref().is_some_and(|(nearest, _)| *nearest <= distance) {
                return;
            }

            let target = Target::of_mob(other);

            if !must_see || self.can_see(mob, &target) {
                best = Some((distance, target));
            }
        });

        best.map(|(_, target)| target)
    }

    fn can_see(&self, mob: &Mob, target: &Target) -> bool {
        blocks::can_see(self.view, [mob.x, mob.y + mob.eye_height(), mob.z], [target.x, target.y + target.eye, target.z])
    }

    fn set_target(&mut self, mob: &mut Mob, id: u64) {
        if mob.target != id {
            mob.target = id;
            self.events.push(Event { id: mob.id, kind: EVENT_TARGET, value: 0.0, block: [0; 3], other: id });
        }
    }

    fn in_water(&self, target: &Target) -> bool {
        let (x, z) = (target.x.floor() as i32, target.z.floor() as i32);

        (self.view.block(x, (target.y + 0.05).floor() as i32, z) | self.view.block(x, (target.y + 0.4).floor() as i32, z)) & blocks::WATER != 0
    }

    fn nearest_target(&self, mob: &Mob, range: f64, must_see: bool, in_water: bool) -> Option<Target> {
        self.players
            .iter()
            .filter(|player| player.flags & (PLAYER_SPECTATOR | PLAYER_NO_TARGET) == 0 && (player.y - mob.y).abs() <= 4.0 + mob.height)
            .map(Target::of)
            .filter(|target| !in_water || self.in_water(target))
            .filter(|target| {
                let reach = (range * target.visibility()).max(2.0);
                mob.distance_sq(target.x, target.y, target.z) <= reach * reach
            })
            .filter(|target| !must_see || self.can_see(mob, target))
            .min_by(|a, b| mob.distance_sq(a.x, a.y, a.z).total_cmp(&mob.distance_sq(b.x, b.y, b.z)))
    }

    fn nearest_player(&self, mob: &Mob, range: f64, fits: impl Fn(&Player) -> bool) -> Option<&Player> {
        self.players
            .iter()
            .filter(|player| player.flags & PLAYER_SPECTATOR == 0 && fits(player))
            .map(|player| (mob.distance_sq(player.x, player.y, player.z), player))
            .filter(|(distance, _)| *distance <= range * range)
            .min_by(|a, b| a.0.total_cmp(&b.0))
            .map(|(_, player)| player)
    }

    fn nearest_kin(&self, mob: &Mob, range: f64, fits: impl Fn(&Info) -> bool) -> Option<&Info> {
        let mut best: Option<(f64, &Info)> = None;

        self.grid.near(mob.x, mob.z, range, |index| {
            let other = &self.infos[index];

            if other.id == mob.id || other.kind != mob.kind || !fits(other) || (other.y - mob.y).abs() > range / 2.0 {
                return;
            }

            let distance = mob.distance_sq(other.x, other.y, other.z);

            if distance <= range * range && best.map_or(true, |(known, _)| distance < known) {
                best = Some((distance, other));
            }
        });

        best.map(|(_, info)| info)
    }

    fn random_spot(&mut self, mob: &Mob, reach: i32, rise: i32) -> Option<Node> {
        let walker = mob.walker();
        let (bx, by, bz) = (mob.x.floor() as i32, (mob.y + 0.5).floor() as i32, mob.z.floor() as i32);
        let mut best: Option<(i32, Node)> = None;

        for _ in 0..10 {
            let x = bx + self.random.next_int_bounded(2 * reach + 1) - reach;
            let z = bz + self.random.next_int_bounded(2 * reach + 1) - reach;
            let mut y = by + self.random.next_int_bounded(2 * rise + 1) - rise;

            if !self.view.is_loaded(x, z) {
                continue;
            }

            for _ in 0..rise {
                if walker.fits(self.view, x, y, z) {
                    break;
                }

                y += 1;
            }

            for _ in 0..rise {
                if walker.has_floor(self.view, x, y, z) {
                    break;
                }

                y -= 1;
            }

            if !walker.can_stand(self.view, x, y, z) || (!mob.has(AQUATIC) && self.view.block(x, y, z) & blocks::WATER != 0) {
                continue;
            }

            let score = if self.view.block(x, y - 1, z) & blocks::GRASS_BLOCK != 0 { 10 } else { 0 };

            if best.map_or(true, |(known, _)| score > known) {
                best = Some((score, [x, y, z]));
            }
        }

        best.map(|(_, node)| node)
    }
}

impl Goal {
    pub fn new(kind: u8, priority: u8, params: [f32; 6]) -> Self {
        Goal { kind, priority, params, running: false, timer: 0, count: 0, delay: 0, charge: -1, strafe: (false, false), target: 0, spot: [0.0; 3] }
    }

    fn uses(&self) -> u8 {
        match self.kind {
            FLOAT => JUMP,
            PANIC | FOLLOW_PARENT | RANDOM_STROLL => MOVE,
            BREED | TEMPT | RANDOM_LOOK_AROUND => MOVE | LOOK,
            LOOK_AT_PLAYER => LOOK,
            EAT_BLOCK => MOVE | LOOK | JUMP,
            MELEE_ATTACK => MOVE | LOOK,
            LEAP_AT_TARGET => MOVE | JUMP,
            SWELL => MOVE,
            RANGED_BOW_ATTACK | RANGED_ATTACK | SLIME_ATTACK => MOVE | LOOK,
            SLIME_RANDOM_DIRECTION => LOOK,
            NEAREST_ATTACKABLE_TARGET | HURT_BY_TARGET => TARGET,
            _ => 0,
        }
    }

    fn pick_spot(&mut self, spot: Option<Node>) -> bool {
        if let Some([x, y, z]) = spot {
            self.spot = [x as f64 + 0.5, y as f64, z as f64 + 0.5];
        }

        spot.is_some()
    }

    fn grass_at(mob: &Mob, view: &View) -> Option<(Node, bool)> {
        let (x, y, z) = (mob.x.floor() as i32, (mob.y + 0.05).floor() as i32, mob.z.floor() as i32);

        if view.block(x, y, z) & blocks::GRASS_PLANT != 0 {
            Some(([x, y, z], true))
        } else if view.block(x, y - 1, z) & blocks::GRASS_BLOCK != 0 {
            Some(([x, y - 1, z], false))
        } else {
            None
        }
    }

    fn within_reach(mob: &Mob, target: &Target) -> bool {
        let reach = mob.width / 2.0 + ATTACK_REACH + target.width / 2.0;

        (mob.x - target.x).abs() < reach && (mob.z - target.z).abs() < reach && target.y < mob.y + mob.height && target.y + target.height > mob.y
    }

    fn wants_in_water(&self, mob: &Mob) -> bool {
        self.kind == NEAREST_ATTACKABLE_TARGET && self.params[3] == IN_WATER_BY_DAY && mob.has(DAYTIME)
    }

    fn target_kind(&self) -> u32 {
        ((self.params[4] as u32) << 16) | self.params[5] as u32
    }

    fn keeps_target(&mut self, mob: &Mob, context: &Context) -> bool {
        if mob.has(CALM) && context.is_player(mob.target) {
            return false;
        }

        let Some(target) = context.target(mob.target) else { return false };
        let range = self.params[0] as f64;

        if mob.target != self.target || mob.distance_sq(target.x, target.y, target.z) > range * range {
            return false;
        }

        if self.wants_in_water(mob) && !context.in_water(&target) {
            return false;
        }

        if self.params[1] > 0.0 {
            if context.can_see(mob, &target) {
                self.count = 0;
            } else {
                self.count += 1;

                if self.count > UNSEEN_MEMORY {
                    return false;
                }
            }
        }

        true
    }

    fn shoot_bow(&mut self, mob: &mut Mob, context: &mut Context) {
        let Some(target) = context.target(mob.target) else { return };
        let distance = mob.distance_sq(target.x, target.y, target.z);
        let radius = (self.params[2] * self.params[2]) as f64;
        let seen = context.can_see(mob, &target);

        if seen != (self.count > 0) {
            self.count = 0;
        }

        self.count += if seen { 1 } else { -1 };

        if distance <= radius && self.count >= 20 {
            mob.nav.stop();
            self.delay += 1;
        } else {
            mob.move_to(context.view, target.x, target.y, target.z, self.params[0]);
            self.delay = -1;
        }

        if self.delay >= 20 {
            if context.chance(0.3) {
                self.strafe.0 = !self.strafe.0;
            }

            if context.chance(0.3) {
                self.strafe.1 = !self.strafe.1;
            }

            self.delay = 0;
        }

        if self.delay > -1 {
            if distance > radius * 0.75 {
                self.strafe.1 = false;
            } else if distance < radius * 0.25 {
                self.strafe.1 = true;
            }

            mob.strafe = Some((if self.strafe.1 { -0.5 } else { 0.5 }, if self.strafe.0 { 0.5 } else { -0.5 }));

            let wanted = ((target.z - mob.z).atan2(target.x - mob.x).to_degrees() - 90.0) as f32;
            mob.yaw = turn(mob.yaw, wanted, 30.0);
        }

        mob.look = Some([target.x, target.y + target.eye, target.z]);

        if self.charge >= 0 {
            self.charge += 1;

            if !seen && self.count < -60 {
                self.charge = -1;
                context.events.push(Event { id: mob.id, kind: EVENT_USE_ITEM, value: 0.0, block: [0; 3], other: 0 });
            } else if seen && self.charge >= 20 {
                let charge = self.charge as f32 / 20.0;
                let power = ((charge * charge + charge * 2.0) / 3.0).min(1.0);

                self.charge = -1;
                self.timer = self.params[1] as i32;
                context.events.push(Event { id: mob.id, kind: EVENT_USE_ITEM, value: 0.0, block: [0; 3], other: 0 });
                context.events.push(Event { id: mob.id, kind: EVENT_SHOOT, value: power, block: [0; 3], other: target.id });
            }
        } else {
            self.timer -= 1;

            if self.timer <= 0 && self.count >= -60 {
                self.charge = 0;
                context.events.push(Event { id: mob.id, kind: EVENT_USE_ITEM, value: 1.0, block: [0; 3], other: 0 });
            }
        }
    }

    fn throw(&mut self, mob: &mut Mob, context: &mut Context) {
        let Some(target) = context.target(mob.target) else { return };
        let distance = mob.distance_sq(target.x, target.y, target.z);
        let radius = self.params[3] as f64;
        let seen = context.can_see(mob, &target);

        self.count = if seen { self.count + 1 } else { 0 };

        if distance <= radius * radius && self.count >= 5 {
            mob.nav.stop();
        } else if self.delay <= 0 || mob.nav.is_done() {
            self.delay = 10;
            mob.move_to(context.view, target.x, target.y, target.z, self.params[0]);
        }

        self.delay -= 1;
        mob.look = Some([target.x, target.y + target.eye, target.z]);

        let wanted = ((target.z - mob.z).atan2(target.x - mob.x).to_degrees() - 90.0) as f32;
        mob.yaw = turn(mob.yaw, wanted, 30.0);

        self.timer -= 1;

        if self.timer == 0 {
            if seen {
                let power = (distance.sqrt() / radius).clamp(0.1, 1.0) as f32;

                context.events.push(Event { id: mob.id, kind: EVENT_SHOOT, value: power, block: [0; 3], other: target.id });
                self.timer = self.next_interval(context);
            }
        } else if self.timer < 0 {
            self.timer = self.next_interval(context);
        }
    }

    fn next_interval(&self, context: &mut Context) -> i32 {
        let (min, max) = (self.params[1] as i32, (self.params[2] as i32).max(self.params[1] as i32));

        min + context.random.next_int_bounded(max - min + 1)
    }

    fn too_close(&self, mob: &Mob, context: &Context) -> bool {
        let close = self.params[4] as f64;

        close > 0.0 && context.target(mob.target).is_some_and(|target| mob.distance_sq(target.x, target.y, target.z) < close * close)
    }

    fn too_far(&self, mob: &Mob, context: &Context) -> bool {
        let far = self.params[3] as f64;

        far > 0.0 && context.target(mob.target).is_some_and(|target| mob.distance_sq(target.x, target.y, target.z) > far * far)
    }

    fn can_start(&mut self, mob: &mut Mob, context: &mut Context) -> bool {
        match self.kind {
            NEAREST_ATTACKABLE_TARGET => {
                let interval = self.params[2] as i32;

                let kind = self.target_kind();

                if (kind == 0 && mob.has(CALM)) || (self.params[3] == ONLY_IN_DARK && mob.has(BRIGHT)) {
                    return false;
                }

                if interval > 0 && context.random.next_int_bounded(interval) != 0 {
                    return false;
                }

                let found = if kind == 0 {
                    context.nearest_target(mob, self.params[0] as f64, self.params[1] > 0.0, self.wants_in_water(mob))
                } else {
                    context.nearest_mob(mob, kind, self.params[0] as f64, self.params[1] > 0.0)
                };

                match found {
                    Some(target) => {
                        self.target = target.id;
                        true
                    }
                    None => false,
                }
            }
            HURT_BY_TARGET => {
                if (mob.has(CALM) && context.is_player(mob.hurt_by)) || mob.hurt_count as i32 == self.delay || context.target(mob.hurt_by).is_none() {
                    return false;
                }

                self.target = mob.hurt_by;
                true
            }
            LEAP_AT_TARGET => {
                let Some(target) = context.target(mob.target) else { return false };
                let distance = mob.distance_sq(target.x, target.y, target.z);

                mob.is(ON_GROUND) && (4.0..=16.0).contains(&distance) && context.random.next_int_bounded(5) == 0
            }
            SWELL => {
                self.count > 0 || context.target(mob.target).is_some_and(|target| mob.distance_sq(target.x, target.y, target.z) < 9.0)
            }
            RANGED_BOW_ATTACK => context.target(mob.target).is_some(),
            RANGED_ATTACK => context.target(mob.target).is_some() && !self.too_close(mob, context),
            SLIME_ATTACK => context.target(mob.target).is_some(),
            SLIME_RANDOM_DIRECTION => mob.target == 0 && mob.is(ON_GROUND | IN_WATER | IN_LAVA),
            MELEE_ATTACK => {
                if self.delay > 0 {
                    self.delay -= 1;
                    return false;
                }

                self.delay = 20;

                let Some(target) = context.target(mob.target) else { return false };

                if self.too_far(mob, context) {
                    return false;
                }

                mob.move_to(context.view, target.x, target.y, target.z, self.params[0]) || Self::within_reach(mob, &target) || (mob.has(AQUATIC) && mob.is(IN_WATER))
            }
            FLOAT => mob.is(IN_WATER | IN_LAVA),
            PANIC => {
                if mob.hurt <= 0 && !mob.has(ON_FIRE) {
                    return false;
                }

                let spot = context.random_spot(mob, 5, 4);
                self.pick_spot(spot)
            }
            BREED => {
                if !mob.has(IN_LOVE) || mob.has(BABY) {
                    return false;
                }

                match context.nearest_kin(mob, 8.0, |other| other.flags & IN_LOVE != 0 && other.flags & BABY == 0) {
                    Some(partner) => {
                        self.target = partner.id;
                        self.timer = 0;
                        self.count = 0;
                        true
                    }
                    None => false,
                }
            }
            TEMPT => {
                if self.count > 0 {
                    self.count -= 1;
                    return false;
                }

                let group = 1u64 << (self.params[1] as u32).min(63);

                match context.nearest_player(mob, 10.0, |player| player.tempt & group != 0) {
                    Some(player) => {
                        self.target = player.id;
                        self.timer = 0;
                        true
                    }
                    None => false,
                }
            }
            FOLLOW_PARENT => {
                if !mob.has(BABY) {
                    return false;
                }

                match context.nearest_kin(mob, 8.0, |other| other.flags & BABY == 0) {
                    Some(parent) if mob.distance_sq(parent.x, parent.y, parent.z) >= 9.0 => {
                        self.target = parent.id;
                        self.timer = 0;
                        true
                    }
                    _ => false,
                }
            }
            RANDOM_STROLL => {
                if context.random.next_int_bounded(self.params[1].max(1.0) as i32) != 0 {
                    return false;
                }

                let spot = context.random_spot(mob, 10, 7);
                self.pick_spot(spot)
            }
            LOOK_AT_PLAYER => {
                if !context.chance(self.params[1]) {
                    return false;
                }

                match context.nearest_player(mob, self.params[0] as f64, |_| true) {
                    Some(player) => {
                        self.target = player.id;
                        self.timer = 40 + context.random.next_int_bounded(40);
                        true
                    }
                    None => false,
                }
            }
            RANDOM_LOOK_AROUND => {
                if !context.chance(0.02) {
                    return false;
                }

                let angle = context.random.next_float() as f64 * std::f64::consts::TAU;

                self.spot = [angle.cos(), 0.0, angle.sin()];
                self.timer = 20 + context.random.next_int_bounded(20);
                true
            }
            EAT_BLOCK => {
                let chance = if mob.has(BABY) { self.params[1] } else { self.params[0] };

                context.chance(chance) && Self::grass_at(mob, context.view).is_some()
            }
            _ => false,
        }
    }

    fn start(&mut self, mob: &mut Mob, context: &mut Context) {
        match self.kind {
            NEAREST_ATTACKABLE_TARGET | HURT_BY_TARGET => {
                if self.kind == HURT_BY_TARGET {
                    self.delay = mob.hurt_count as i32;
                }

                self.count = 0;
                context.set_target(mob, self.target);
            }
            MELEE_ATTACK => {
                self.timer = 0;
                self.count = 0;
                self.spot = [0.0; 3];
            }
            LEAP_AT_TARGET => {
                if let Some(target) = context.target(mob.target) {
                    let (dx, dz) = (target.x - mob.x, target.z - mob.z);
                    let length = (dx * dx + dz * dz).sqrt();

                    if length > 1.0e-7 {
                        mob.mx = dx / length * 0.4 + mob.mx * 0.2;
                        mob.mz = dz / length * 0.4 + mob.mz * 0.2;
                    }

                    mob.my = self.params[0] as f64;
                }
            }
            SWELL => mob.nav.stop(),
            RANGED_BOW_ATTACK => {
                self.timer = -1;
                self.count = 0;
                self.delay = -1;
            }
            RANGED_ATTACK => {
                self.timer = -1;
                self.count = 0;
                self.delay = 0;
                context.events.push(Event { id: mob.id, kind: EVENT_USE_ITEM, value: 1.0, block: [0; 3], other: 0 });
            }
            PANIC | RANDOM_STROLL => {
                let [x, y, z] = self.spot;
                mob.move_to(context.view, x, y, z, self.params[0]);
            }
            EAT_BLOCK => {
                self.timer = 40;
                mob.nav.stop();
                context.events.push(Event { id: mob.id, kind: EVENT_EAT_START, value: 0.0, block: [0; 3], other: 0 });
            }
            _ => {}
        }
    }

    fn can_continue(&mut self, mob: &mut Mob, context: &mut Context) -> bool {
        match self.kind {
            NEAREST_ATTACKABLE_TARGET | HURT_BY_TARGET => self.keeps_target(mob, context),
            MELEE_ATTACK => {
                if self.params[2] > 0.0 && mob.has(BRIGHT) && context.random.next_int_bounded(100) == 0 {
                    context.set_target(mob, 0);
                    return false;
                }

                context.target(mob.target).is_some() && !self.too_far(mob, context) && (self.params[1] > 0.0 || !mob.nav.is_done() || (mob.has(AQUATIC) && mob.is(IN_WATER)))
            }
            RANGED_ATTACK => context.target(mob.target).is_some() && !self.too_close(mob, context),
            SLIME_ATTACK => context.target(mob.target).is_some(),
            SLIME_RANDOM_DIRECTION => mob.target == 0,
            LEAP_AT_TARGET => !mob.is(ON_GROUND),
            RANGED_BOW_ATTACK => context.target(mob.target).is_some() || !mob.nav.is_done(),
            SWELL => self.count > 0 || context.target(mob.target).is_some_and(|target| mob.distance_sq(target.x, target.y, target.z) < 9.0),
            FLOAT => mob.is(IN_WATER | IN_LAVA),
            PANIC | RANDOM_STROLL => !mob.nav.is_done(),
            BREED => mob.has(IN_LOVE) && self.count < 60 && context.info(self.target).is_some_and(|partner| partner.flags & IN_LOVE != 0),
            TEMPT => {
                let group = 1u64 << (self.params[1] as u32).min(63);

                context.player(self.target).is_some_and(|player| player.tempt & group != 0 && mob.distance_sq(player.x, player.y, player.z) <= 100.0)
            }
            FOLLOW_PARENT => {
                mob.has(BABY)
                    && context.info(self.target).is_some_and(|parent| {
                        let distance = mob.distance_sq(parent.x, parent.y, parent.z);
                        (9.0..=256.0).contains(&distance)
                    })
            }
            LOOK_AT_PLAYER => self.timer > 0 && context.player(self.target).is_some_and(|player| mob.distance_sq(player.x, player.y, player.z) <= (self.params[0] * self.params[0]) as f64),
            RANDOM_LOOK_AROUND | EAT_BLOCK => self.timer > 0,
            _ => false,
        }
    }

    fn stop(&mut self, mob: &mut Mob, context: &mut Context) {
        match self.kind {
            PANIC | RANDOM_STROLL | FOLLOW_PARENT | BREED | MELEE_ATTACK => mob.nav.stop(),
            RANGED_ATTACK => {
                mob.nav.stop();
                context.events.push(Event { id: mob.id, kind: EVENT_USE_ITEM, value: 0.0, block: [0; 3], other: 0 });
            }
            RANGED_BOW_ATTACK => {
                self.count = 0;
                self.timer = -1;

                if self.charge >= 0 {
                    self.charge = -1;
                    context.events.push(Event { id: mob.id, kind: EVENT_USE_ITEM, value: 0.0, block: [0; 3], other: 0 });
                }
            }
            NEAREST_ATTACKABLE_TARGET | HURT_BY_TARGET => {
                if mob.target == self.target {
                    context.set_target(mob, 0);
                }
            }
            TEMPT => {
                mob.nav.stop();
                self.count = 100;
            }
            _ => {}
        }
    }

    fn run(&mut self, mob: &mut Mob, context: &mut Context) {
        match self.kind {
            RANGED_BOW_ATTACK => self.shoot_bow(mob, context),
            RANGED_ATTACK => self.throw(mob, context),
            SLIME_ATTACK => {
                if let Some(target) = context.target(mob.target) {
                    let wanted = ((target.z - mob.z).atan2(target.x - mob.x).to_degrees() - 90.0) as f32;

                    mob.yaw = turn(mob.yaw, wanted, 10.0);
                    mob.head_yaw = mob.yaw;
                }
            }
            SLIME_RANDOM_DIRECTION => {
                self.timer -= 1;

                if self.timer <= 0 {
                    self.timer = 40 + context.random.next_int_bounded(60);
                    self.count = context.random.next_int_bounded(360);
                }

                mob.yaw = turn(mob.yaw, self.count as f32, 90.0);
                mob.head_yaw = mob.yaw;
            }
            SWELL => {
                let direction = match context.target(mob.target) {
                    Some(target) if mob.distance_sq(target.x, target.y, target.z) <= 49.0 && context.can_see(mob, &target) => 1,
                    _ => -1,
                };

                if direction != self.count {
                    self.count = direction;
                    context.events.push(Event { id: mob.id, kind: EVENT_SWELL, value: direction as f32, block: [0; 3], other: 0 });
                }
            }
            MELEE_ATTACK => {
                let Some(target) = context.target(mob.target) else { return };
                let seen = context.can_see(mob, &target);

                mob.look = Some([target.x, target.y + target.eye, target.z]);
                self.timer = (self.timer - 1).max(0);

                let [sx, sy, sz] = self.spot;
                let moved = (target.x - sx).powi(2) + (target.y - sy).powi(2) + (target.z - sz).powi(2) >= 1.0;

                if (self.params[1] > 0.0 || seen) && self.timer <= 0 && (self.spot == [0.0; 3] || moved || context.chance(0.05)) {
                    self.spot = [target.x, target.y, target.z];
                    self.timer = 4 + context.random.next_int_bounded(7);

                    let distance = mob.distance_sq(target.x, target.y, target.z);

                    if distance > 1024.0 {
                        self.timer += 10;
                    } else if distance > 256.0 {
                        self.timer += 5;
                    }

                    if !mob.move_to(context.view, target.x, target.y, target.z, self.params[0]) {
                        self.timer += 15;
                    }
                }

                self.count = (self.count - 1).max(0);

                if self.count <= 0 && seen && Self::within_reach(mob, &target) {
                    self.count = ATTACK_COOLDOWN;
                    context.events.push(Event { id: mob.id, kind: EVENT_ATTACK, value: 0.0, block: [0; 3], other: target.id });
                }
            }
            FLOAT => {
                let body = context.view.block(mob.x.floor() as i32, (mob.y + 0.4).floor() as i32, mob.z.floor() as i32);

                if context.chance(0.8) && body & (blocks::WATER | blocks::LAVA) != 0 {
                    mob.jump = true;
                }
            }
            BREED => {
                let Some(partner) = context.info(self.target) else { return };
                let (x, y, z, id) = (partner.x, partner.y, partner.z, partner.id);

                mob.look = Some([x, y + partner.height * 0.85, z]);

                if self.timer % 10 == 0 {
                    mob.move_to(context.view, x, y, z, self.params[0]);
                }

                self.timer += 1;

                if mob.distance_sq(x, y, z) < 9.0 {
                    self.count += 1;
                }

                if self.count >= 60 && mob.id < id {
                    mob.flags &= !IN_LOVE;
                    context.out_of_love.push(id);
                    context.events.push(Event { id: mob.id, kind: EVENT_BRED, value: 0.0, block: [0; 3], other: id });
                }
            }
            TEMPT => {
                let Some(player) = context.player(self.target) else { return };
                let (x, y, z, eye) = (player.x, player.y, player.z, player.eye);

                mob.look = Some([x, y + eye, z]);

                if mob.distance_sq(x, y, z) < 6.25 {
                    mob.nav.stop();
                } else if self.timer % 10 == 0 || mob.nav.is_done() {
                    mob.move_to(context.view, x, y, z, self.params[0]);
                }

                self.timer += 1;
            }
            FOLLOW_PARENT => {
                if self.timer % 10 == 0 {
                    if let Some(parent) = context.info(self.target) {
                        let (x, y, z) = (parent.x, parent.y, parent.z);
                        mob.move_to(context.view, x, y, z, self.params[0]);
                    }
                }

                self.timer += 1;
            }
            LOOK_AT_PLAYER => {
                if let Some(player) = context.player(self.target) {
                    mob.look = Some([player.x, player.y + player.eye, player.z]);
                }

                self.timer -= 1;
            }
            RANDOM_LOOK_AROUND => {
                mob.look = Some([mob.x + self.spot[0], mob.y + mob.eye_height(), mob.z + self.spot[2]]);
                self.timer -= 1;
            }
            EAT_BLOCK => {
                self.timer -= 1;

                if self.timer == 4 {
                    if let Some((block, plant)) = Self::grass_at(mob, context.view) {
                        context.events.push(Event { id: mob.id, kind: EVENT_EAT_BLOCK, value: if plant { 1.0 } else { 0.0 }, block, other: 0 });
                    }
                }
            }
            _ => {}
        }
    }
}

pub fn tick(mob: &mut Mob, context: &mut Context) {
    let mut goals = std::mem::take(&mut mob.goals);

    for goal in goals.iter_mut().filter(|goal| goal.running) {
        if !goal.can_continue(mob, context) {
            goal.running = false;
            goal.stop(mob, context);
        }
    }

    for index in 0..goals.len() {
        if goals[index].running {
            continue;
        }

        let (uses, priority) = (goals[index].uses(), goals[index].priority);

        if goals.iter().any(|other| other.running && other.uses() & uses != 0 && other.priority <= priority) {
            continue;
        }

        if !goals[index].can_start(mob, context) {
            continue;
        }

        for other in goals.iter_mut().filter(|other| other.running && other.uses() & uses != 0) {
            other.running = false;
            other.stop(mob, context);
        }

        goals[index].running = true;
        goals[index].start(mob, context);
    }

    for goal in goals.iter_mut().filter(|goal| goal.running) {
        goal.run(mob, context);
    }

    mob.goals = goals;
}