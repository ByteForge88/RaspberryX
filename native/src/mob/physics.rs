//! Moving a mob's box through the blocks, the way the game does it: down or up first, then
//! along x, then along z, stopping at whatever is in the way, and stepping up small ledges.

use super::blocks::{self, View};

/// a mob walks up anything this high without jumping, slabs for one
pub const STEP_HEIGHT: f64 = 0.6;

pub const GRAVITY: f64 = 0.08;
pub const JUMP: f64 = 0.42;

type Box3 = [f64; 6];

fn clip(moving: &Box3, block: &Box3, axis: usize, mut delta: f64) -> f64 {
    let (a, b) = ((axis + 1) % 3, (axis + 2) % 3);

    //only boxes that overlap on the other two axes can be in the way
    if moving[a + 3] <= block[a] || moving[a] >= block[a + 3] || moving[b + 3] <= block[b] || moving[b] >= block[b + 3] {
        return delta;
    }

    if delta > 0.0 && moving[axis + 3] <= block[axis] {
        delta = delta.min(block[axis] - moving[axis + 3]);
    } else if delta < 0.0 && moving[axis] >= block[axis + 3] {
        delta = delta.max(block[axis + 3] - moving[axis]);
    }

    delta
}

fn slide(moving: &mut Box3, blocks: &[Box3], axis: usize, mut delta: f64) -> f64 {
    if delta != 0.0 {
        for block in blocks {
            delta = clip(moving, block, axis, delta);
        }

        moving[axis] += delta;
        moving[axis + 3] += delta;
    }

    delta
}

fn boxes_around(view: &View, area: &Box3) -> Vec<Box3> {
    let mut boxes = Vec::new();

    for x in area[0].floor() as i32..=area[3].floor() as i32 {
        for z in area[2].floor() as i32..=area[5].floor() as i32 {
            //one lower than the area, a fence reaches half a block into the space above it
            for y in area[1].floor() as i32 - 1..=area[4].floor() as i32 {
                let height = blocks::height(view.block(x, y, z));

                if height > 0.0 {
                    boxes.push([x as f64, y as f64, z as f64, x as f64 + 1.0, y as f64 + height, z as f64 + 1.0]);
                }
            }
        }
    }

    boxes
}

pub struct Moved {
    pub x: f64,
    pub y: f64,
    pub z: f64,
    pub hit_x: bool,
    pub hit_y: bool,
    pub hit_z: bool,
    pub on_ground: bool,
}

/// Moves a box of `width` and `height` standing at x y z by dx dy dz as far as the blocks let it.
pub fn move_box(view: &View, x: f64, y: f64, z: f64, width: f64, height: f64, dx: f64, dy: f64, dz: f64, was_on_ground: bool) -> Moved {
    let half = width / 2.0;
    let start: Box3 = [x - half, y, z - half, x + half, y + height, z + half];

    let area: Box3 = [
        start[0] + dx.min(0.0),
        start[1] + dy.min(0.0),
        start[2] + dz.min(0.0),
        start[3] + dx.max(0.0),
        start[4] + dy.max(0.0) + STEP_HEIGHT,
        start[5] + dz.max(0.0),
    ];
    let blocks = boxes_around(view, &area);

    let mut moved = start;
    let my = slide(&mut moved, &blocks, 1, dy);
    let mut mx = slide(&mut moved, &blocks, 0, dx);
    let mut mz = slide(&mut moved, &blocks, 2, dz);
    let mut on_ground = dy < 0.0 && my != dy;

    //blocked sideways while standing: see if going up a step gets further
    if (mx != dx || mz != dz) && (was_on_ground || on_ground) {
        let mut stepped = start;
        let up = slide(&mut stepped, &blocks, 1, STEP_HEIGHT);
        let sx = slide(&mut stepped, &blocks, 0, dx);
        let sz = slide(&mut stepped, &blocks, 2, dz);
        let down = slide(&mut stepped, &blocks, 1, -up + dy.min(0.0));

        if sx * sx + sz * sz > mx * mx + mz * mz + 1.0e-9 {
            moved = stepped;
            mx = sx;
            mz = sz;
            on_ground = down != -up + dy.min(0.0) || on_ground;
        }
    }

    Moved {
        x: (moved[0] + moved[3]) / 2.0,
        y: moved[1],
        z: (moved[2] + moved[5]) / 2.0,
        hit_x: mx != dx,
        hit_y: my != dy,
        hit_z: mz != dz,
        on_ground,
    }
}

/// Whether a box fits somewhere without touching a block.
pub fn is_free(view: &View, x: f64, y: f64, z: f64, width: f64, height: f64) -> bool {
    let half = width / 2.0;
    let area: Box3 = [x - half, y, z - half, x + half, y + height, z + half];

    boxes_around(view, &area).iter().all(|block| {
        area[3] <= block[0] || area[0] >= block[3] || area[4] <= block[1] || area[1] >= block[4] || area[5] <= block[2] || area[2] >= block[5]
    })
}