//! What a mob wants to do. Every mob has a list of goals with priorities, the way the game's
//! own mobs do: a goal that can start takes over from less important ones that use the same
//! part of the mob (its legs, its head, its jumping), and runs until it is done.

use std::collections::HashMap;

use crate::random::JavaRandom;

use super::blocks::{self, View};
use super::path::Node;
use super::{Event, Grid, Info, Mob, Player, BABY, EVENT_BRED, EVENT_EAT_BLOCK, EVENT_EAT_START, IN_LAVA, IN_LOVE, IN_WATER, ON_FIRE, PLAYER_SPECTATOR};

pub const FLOAT: u8 = 1;
pub const PANIC: u8 = 2;
pub const BREED: u8 = 3;
pub const TEMPT: u8 = 4;
pub const FOLLOW_PARENT: u8 = 5;
pub const RANDOM_STROLL: u8 = 6;
pub const LOOK_AT_PLAYER: u8 = 7;
pub const RANDOM_LOOK_AROUND: u8 = 8;
pub const EAT_BLOCK: u8 = 9;

const MOVE: u8 = 1;
const LOOK: u8 = 2;
const JUMP: u8 = 4;

pub struct Goal {
    pub kind: u8,
    pub priority: u8,
    pub params: [f32; 4],
    running: bool,
    timer: i32,
    count: i32,
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

            if !walker.can_stand(self.view, x, y, z) || self.view.block(x, y, z) & blocks::WATER != 0 {
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
    pub fn new(kind: u8, priority: u8, params: [f32; 4]) -> Self {
        Goal { kind, priority, params, running: false, timer: 0, count: 0, target: 0, spot: [0.0; 3] }
    }

    fn uses(&self) -> u8 {
        match self.kind {
            FLOAT => JUMP,
            PANIC | FOLLOW_PARENT | RANDOM_STROLL => MOVE,
            BREED | TEMPT | RANDOM_LOOK_AROUND => MOVE | LOOK,
            LOOK_AT_PLAYER => LOOK,
            EAT_BLOCK => MOVE | LOOK | JUMP,
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

    fn can_start(&mut self, mob: &mut Mob, context: &mut Context) -> bool {
        match self.kind {
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

    fn stop(&mut self, mob: &mut Mob) {
        match self.kind {
            PANIC | RANDOM_STROLL | FOLLOW_PARENT | BREED => mob.nav.stop(),
            TEMPT => {
                mob.nav.stop();
                self.count = 100;
            }
            _ => {}
        }
    }

    fn run(&mut self, mob: &mut Mob, context: &mut Context) {
        match self.kind {
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
            goal.stop(mob);
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
            other.stop(mob);
        }

        goals[index].running = true;
        goals[index].start(mob, context);
    }

    for goal in goals.iter_mut().filter(|goal| goal.running) {
        goal.run(mob, context);
    }

    mob.goals = goals;
}