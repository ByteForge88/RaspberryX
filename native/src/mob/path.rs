//! Finding a way to walk somewhere: A* over the blocks a mob can stand on. A step goes to a
//! neighbor on the same level, one block up, or down a drop of up to three.

use std::cmp::Ordering;
use std::collections::{BinaryHeap, HashMap};

use super::blocks::{self, View};

pub type Node = [i32; 3];

const MAX_DROP: i32 = 3;

const WATER_COST: f32 = 8.0;
const DANGER_COST: f32 = 8.0;

#[derive(Clone, Copy)]
pub struct Walker {
    pub tall: i32,
    pub avoid_water: bool,
}

impl Walker {
    pub fn new(height: f64, avoid_water: bool) -> Self {
        Walker { tall: (height.ceil() as i32).max(1), avoid_water }
    }

    pub fn fits(&self, view: &View, x: i32, y: i32, z: i32) -> bool {
        if blocks::height(view.block(x, y - 1, z)) > 1.0 {
            return false;
        }

        (0..self.tall).all(|up| {
            let flags = view.block(x, y + up, z);
            !blocks::is_solid(flags) && flags & (blocks::LAVA | blocks::DAMAGE) == 0
        })
    }

    pub fn has_floor(&self, view: &View, x: i32, y: i32, z: i32) -> bool {
        let below = view.block(x, y - 1, z);

        (blocks::is_solid(below) && below & blocks::DAMAGE == 0) || view.block(x, y, z) & blocks::WATER != 0
    }

    pub fn can_stand(&self, view: &View, x: i32, y: i32, z: i32) -> bool {
        self.fits(view, x, y, z) && self.has_floor(view, x, y, z)
    }

    fn step(&self, view: &View, from: Node, x: i32, z: i32, may_climb: bool) -> Option<Node> {
        let y = from[1];

        if self.fits(view, x, y, z) {
            return (0..=MAX_DROP).map(|drop| y - drop).take_while(|ny| self.fits(view, x, *ny, z)).find(|ny| self.has_floor(view, x, *ny, z)).map(|ny| [x, ny, z]);
        }

        if may_climb && !blocks::is_solid(view.block(from[0], y + self.tall, from[2])) && self.can_stand(view, x, y + 1, z) {
            return Some([x, y + 1, z]);
        }

        None
    }

    fn cost(&self, view: &View, node: Node) -> f32 {
        let [x, y, z] = node;
        let mut cost = 0.0;

        if self.avoid_water && view.block(x, y, z) & blocks::WATER != 0 {
            cost += WATER_COST;
        }

        for (dx, dz) in [(1, 0), (-1, 0), (0, 1), (0, -1)] {
            if (view.block(x + dx, y, z + dz) | view.block(x + dx, y - 1, z + dz)) & (blocks::DAMAGE | blocks::LAVA) != 0 {
                cost += DANGER_COST;
                break;
            }
        }

        cost
    }

    pub fn start(&self, view: &View, x: f64, y: f64, z: f64) -> Node {
        let (bx, bz) = (x.floor() as i32, z.floor() as i32);
        let mut by = (y + 0.5).floor() as i32;

        for _ in 0..8 {
            if self.has_floor(view, bx, by, bz) {
                break;
            }

            by -= 1;
        }

        [bx, by, bz]
    }
}

struct Open {
    score: f32,
    node: Node,
}

impl PartialEq for Open {
    fn eq(&self, other: &Self) -> bool {
        self.score == other.score
    }
}

impl Eq for Open {}

impl PartialOrd for Open {
    fn partial_cmp(&self, other: &Self) -> Option<Ordering> {
        Some(self.cmp(other))
    }
}

impl Ord for Open {
    fn cmp(&self, other: &Self) -> Ordering {
        other.score.partial_cmp(&self.score).unwrap_or(Ordering::Equal)
    }
}

fn distance(a: Node, b: Node) -> f32 {
    let (dx, dy, dz) = ((a[0] - b[0]) as f32, (a[1] - b[1]) as f32, (a[2] - b[2]) as f32);
    (dx * dx + dy * dy + dz * dz).sqrt()
}

pub fn find(view: &View, walker: Walker, start: Node, goal: Node, range: f32, budget: usize) -> Vec<Node> {
    let mut open = BinaryHeap::new();
    let mut seen: HashMap<Node, (f32, Node)> = HashMap::new();
    let mut closest = (distance(start, goal), start);
    let mut visited = 0;

    seen.insert(start, (0.0, start));
    open.push(Open { score: closest.0, node: start });

    while let Some(Open { node, .. }) = open.pop() {
        if node == goal {
            closest = (0.0, node);
            break;
        }

        visited += 1;

        if visited > budget {
            break;
        }

        let walked = seen[&node].0;
        let mut sides: [Option<Node>; 4] = [None; 4];
        let mut next = Vec::with_capacity(8);

        for (index, (dx, dz)) in [(1, 0), (0, 1), (-1, 0), (0, -1)].into_iter().enumerate() {
            sides[index] = walker.step(view, node, node[0] + dx, node[2] + dz, true);

            if let Some(side) = sides[index] {
                next.push((side, 1.0));
            }
        }

        for (index, (dx, dz)) in [(1, 1), (-1, 1), (-1, -1), (1, -1)].into_iter().enumerate() {
            let level = |side: Option<Node>| side.is_some_and(|side| side[1] <= node[1]);

            if level(sides[index]) && level(sides[(index + 1) % 4]) {
                if let Some(corner) = walker.step(view, node, node[0] + dx, node[2] + dz, false) {
                    next.push((corner, 1.414));
                }
            }
        }

        for (neighbor, step_cost) in next {
            if distance(neighbor, start) > range {
                continue;
            }

            let cost = walked + step_cost + walker.cost(view, neighbor);

            if seen.get(&neighbor).is_some_and(|(known, _)| *known <= cost) {
                continue;
            }

            seen.insert(neighbor, (cost, node));

            let left = distance(neighbor, goal);

            if left < closest.0 {
                closest = (left, neighbor);
            }

            open.push(Open { score: cost + left, node: neighbor });
        }
    }

    let mut path = Vec::new();
    let mut node = closest.1;

    while node != start {
        path.push(node);
        node = seen[&node].1;
    }

    path.reverse();
    path
}