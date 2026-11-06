//! Noise in the classic style: improved Perlin noise and 2D simplex noise, each stacked into
//! octaves. Both are filled into whole grids at once, the way terrain asks for them.

use crate::random::JavaRandom;

/// A permutation table and an offset, both from the world's random.
#[derive(Clone)]
pub struct Perlin {
    permutations: [u8; 512],
    offset_x: f64,
    offset_y: f64,
    offset_z: f64,
}

#[inline]
fn fade(t: f64) -> f64 {
    t * t * t * (t * (t * 6.0 - 15.0) + 10.0)
}

#[inline]
fn lerp(t: f64, a: f64, b: f64) -> f64 {
    a + t * (b - a)
}

#[inline]
fn floor(value: f64) -> i32 {
    let int = value as i32;
    if value < int as f64 { int - 1 } else { int }
}

#[inline]
fn floor_long(value: f64) -> i64 {
    let int = value as i64;
    if value < int as f64 { int - 1 } else { int }
}

#[inline]
fn grad(hash: u8, x: f64, y: f64, z: f64) -> f64 {
    let hash = hash & 15;
    let u = if hash < 8 { x } else { y };
    let v = if hash < 4 { y } else if hash == 12 || hash == 14 { x } else { z };
    (if hash & 1 == 0 { u } else { -u }) + (if hash & 2 == 0 { v } else { -v })
}

#[inline]
fn grad2(hash: u8, x: f64, z: f64) -> f64 {
    let hash = hash & 15;
    let u = (1 - ((hash & 8) >> 3) as i32) as f64 * x;
    let v = if hash < 4 { 0.0 } else if hash == 12 || hash == 14 { x } else { z };
    (if hash & 1 == 0 { u } else { -u }) + (if hash & 2 == 0 { v } else { -v })
}

fn shuffled(random: &mut JavaRandom) -> [u8; 512] {
    let mut permutations = [0u8; 512];
    let mut table: [i32; 256] = std::array::from_fn(|i| i as i32);

    for i in 0..256 {
        let j = random.next_int_bounded(256 - i as i32) as usize + i;
        table.swap(i, j);
        permutations[i] = table[i] as u8;
        permutations[i + 256] = table[i] as u8;
    }

    permutations
}

impl Perlin {
    pub fn new(random: &mut JavaRandom) -> Self {
        let offset_x = random.next_double() * 256.0;
        let offset_y = random.next_double() * 256.0;
        let offset_z = random.next_double() * 256.0;

        Perlin { permutations: shuffled(random), offset_x, offset_y, offset_z }
    }

    #[inline]
    fn p(&self, index: i32) -> i32 {
        self.permutations[(index & 511) as usize] as i32
    }

    /// Adds noise sampled on an sx * sy * sz grid to `out`, laid out x, then z, then y. A grid
    /// one high takes the 2D path.
    #[allow(clippy::too_many_arguments)]
    pub fn add(&self, out: &mut [f64], x: f64, y: f64, z: f64, sx: usize, sy: usize, sz: usize, scale_x: f64, scale_y: f64, scale_z: f64, amplitude: f64) {
        let factor = 1.0 / amplitude;

        if sy == 1 {
            let mut index = 0;

            for i in 0..sx {
                let mut fx = (x + i as f64) * scale_x + self.offset_x;
                let ix = floor(fx);
                let px = ix & 255;
                fx -= ix as f64;
                let ux = fade(fx);

                for k in 0..sz {
                    let mut fz = (z + k as f64) * scale_z + self.offset_z;
                    let iz = floor(fz);
                    let pz = iz & 255;
                    fz -= iz as f64;
                    let uz = fade(fz);

                    let a = self.p(px);
                    let aa = self.p(a) + pz;
                    let b = self.p(px + 1);
                    let ba = self.p(b) + pz;

                    let near = lerp(ux, grad2(self.p(aa) as u8, fx, fz), grad(self.p(ba) as u8, fx - 1.0, 0.0, fz));
                    let far = lerp(ux, grad(self.p(aa + 1) as u8, fx, 0.0, fz - 1.0), grad(self.p(ba + 1) as u8, fx - 1.0, 0.0, fz - 1.0));

                    out[index] += lerp(uz, near, far) * factor;
                    index += 1;
                }
            }

            return;
        }

        let mut index = 0;
        let mut last_y = -1;
        let (mut c0, mut c1, mut c2, mut c3) = (0.0, 0.0, 0.0, 0.0);

        for i in 0..sx {
            let mut fx = (x + i as f64) * scale_x + self.offset_x;
            let ix = floor(fx);
            let px = ix & 255;
            fx -= ix as f64;
            let ux = fade(fx);

            for k in 0..sz {
                let mut fz = (z + k as f64) * scale_z + self.offset_z;
                let iz = floor(fz);
                let pz = iz & 255;
                fz -= iz as f64;
                let uz = fade(fz);

                for j in 0..sy {
                    let mut fy = (y + j as f64) * scale_y + self.offset_y;
                    let iy = floor(fy);
                    let py = iy & 255;
                    fy -= iy as f64;
                    let uy = fade(fy);

                    //the corners only change when the grid crosses into a new cell on y
                    if j == 0 || py != last_y {
                        last_y = py;
                        let a = self.p(px) + py;
                        let aa = self.p(a) + pz;
                        let ab = self.p(a + 1) + pz;
                        let b = self.p(px + 1) + py;
                        let ba = self.p(b) + pz;
                        let bb = self.p(b + 1) + pz;

                        c0 = lerp(ux, grad(self.p(aa) as u8, fx, fy, fz), grad(self.p(ba) as u8, fx - 1.0, fy, fz));
                        c1 = lerp(ux, grad(self.p(ab) as u8, fx, fy - 1.0, fz), grad(self.p(bb) as u8, fx - 1.0, fy - 1.0, fz));
                        c2 = lerp(ux, grad(self.p(aa + 1) as u8, fx, fy, fz - 1.0), grad(self.p(ba + 1) as u8, fx - 1.0, fy, fz - 1.0));
                        c3 = lerp(ux, grad(self.p(ab + 1) as u8, fx, fy - 1.0, fz - 1.0), grad(self.p(bb + 1) as u8, fx - 1.0, fy - 1.0, fz - 1.0));
                    }

                    out[index] += lerp(uz, lerp(uy, c0, c1), lerp(uy, c2, c3)) * factor;
                    index += 1;
                }
            }
        }
    }
}

impl Perlin {
    /// The way 1.8 fills a grid: the start is already scaled and each step adds `step`, which
    /// rounds a little differently from scaling (start + i).
    #[allow(clippy::too_many_arguments)]
    pub fn add_stepped(&self, out: &mut [f64], x: f64, y: f64, z: f64, sx: usize, sy: usize, sz: usize, step_x: f64, step_y: f64, step_z: f64, amplitude: f64) {
        let factor = 1.0 / amplitude;
        let mut index = 0;

        if sy == 1 {
            for i in 0..sx {
                let mut fx = x + i as f64 * step_x + self.offset_x;
                let ix = floor(fx);
                let px = ix & 255;
                fx -= ix as f64;
                let ux = fade(fx);

                for k in 0..sz {
                    let mut fz = z + k as f64 * step_z + self.offset_z;
                    let iz = floor(fz);
                    let pz = iz & 255;
                    fz -= iz as f64;
                    let uz = fade(fz);

                    let a = self.p(px);
                    let aa = self.p(a) + pz;
                    let b = self.p(px + 1);
                    let ba = self.p(b) + pz;

                    let near = lerp(ux, grad2(self.p(aa) as u8, fx, fz), grad(self.p(ba) as u8, fx - 1.0, 0.0, fz));
                    let far = lerp(ux, grad(self.p(aa + 1) as u8, fx, 0.0, fz - 1.0), grad(self.p(ba + 1) as u8, fx - 1.0, 0.0, fz - 1.0));

                    out[index] += lerp(uz, near, far) * factor;
                    index += 1;
                }
            }

            return;
        }

        let mut last_y = -1;
        let (mut c0, mut c1, mut c2, mut c3) = (0.0, 0.0, 0.0, 0.0);

        for i in 0..sx {
            let mut fx = x + i as f64 * step_x + self.offset_x;
            let ix = floor(fx);
            let px = ix & 255;
            fx -= ix as f64;
            let ux = fade(fx);

            for k in 0..sz {
                let mut fz = z + k as f64 * step_z + self.offset_z;
                let iz = floor(fz);
                let pz = iz & 255;
                fz -= iz as f64;
                let uz = fade(fz);

                for j in 0..sy {
                    let mut fy = y + j as f64 * step_y + self.offset_y;
                    let iy = floor(fy);
                    let py = iy & 255;
                    fy -= iy as f64;
                    let uy = fade(fy);

                    if j == 0 || py != last_y {
                        last_y = py;
                        let a = self.p(px) + py;
                        let aa = self.p(a) + pz;
                        let ab = self.p(a + 1) + pz;
                        let b = self.p(px + 1) + py;
                        let ba = self.p(b) + pz;
                        let bb = self.p(b + 1) + pz;

                        c0 = lerp(ux, grad(self.p(aa) as u8, fx, fy, fz), grad(self.p(ba) as u8, fx - 1.0, fy, fz));
                        c1 = lerp(ux, grad(self.p(ab) as u8, fx, fy - 1.0, fz), grad(self.p(bb) as u8, fx - 1.0, fy - 1.0, fz));
                        c2 = lerp(ux, grad(self.p(aa + 1) as u8, fx, fy, fz - 1.0), grad(self.p(ba + 1) as u8, fx - 1.0, fy, fz - 1.0));
                        c3 = lerp(ux, grad(self.p(ab + 1) as u8, fx, fy - 1.0, fz - 1.0), grad(self.p(bb + 1) as u8, fx - 1.0, fy - 1.0, fz - 1.0));
                    }

                    out[index] += lerp(uz, lerp(uy, c0, c1), lerp(uy, c2, c3)) * factor;
                    index += 1;
                }
            }
        }
    }
}

/// Perlin noise in octaves, each twice the frequency and half the strength of the last.
#[derive(Clone)]
pub struct OctavePerlin {
    octaves: Vec<Perlin>,
}

impl OctavePerlin {
    pub fn new(random: &mut JavaRandom, count: usize) -> Self {
        OctavePerlin { octaves: (0..count).map(|_| Perlin::new(random)).collect() }
    }

    /// An sx * sy * sz grid, laid out x, then z, then y.
    #[allow(clippy::too_many_arguments)]
    pub fn grid(&self, x: f64, y: f64, z: f64, sx: usize, sy: usize, sz: usize, scale_x: f64, scale_y: f64, scale_z: f64) -> Vec<f64> {
        let mut out = vec![0.0; sx * sy * sz];
        let mut frequency = 1.0;

        for octave in &self.octaves {
            octave.add(&mut out, x, y, z, sx, sy, sz, scale_x * frequency, scale_y * frequency, scale_z * frequency, frequency);
            frequency /= 2.0;
        }

        out
    }

    /// An sx * sy * sz grid the way 1.8 asks for it: from whole block coordinates, with the
    /// start of every octave wrapped so far away noise does not lose its precision.
    #[allow(clippy::too_many_arguments)]
    pub fn grid_wrapped(&self, x: i32, y: i32, z: i32, sx: usize, sy: usize, sz: usize, scale_x: f64, scale_y: f64, scale_z: f64) -> Vec<f64> {
        let mut out = vec![0.0; sx * sy * sz];
        let mut frequency = 1.0;

        for octave in &self.octaves {
            let mut fx = x as f64 * frequency * scale_x;
            let fy = y as f64 * frequency * scale_y;
            let mut fz = z as f64 * frequency * scale_z;
            let ix = floor_long(fx);
            let iz = floor_long(fz);
            fx -= ix as f64;
            fz -= iz as f64;
            fx += (ix % 16777216) as f64;
            fz += (iz % 16777216) as f64;

            octave.add_stepped(&mut out, fx, fy, fz, sx, sy, sz, scale_x * frequency, scale_y * frequency, scale_z * frequency, frequency);
            frequency /= 2.0;
        }

        out
    }

    /// A flat sx * sz grid.
    pub fn grid_2d(&self, x: f64, z: f64, sx: usize, sz: usize, scale_x: f64, scale_z: f64) -> Vec<f64> {
        self.grid(x, 10.0, z, sx, 1, sz, scale_x, 1.0, scale_z)
    }
}

const SKEW: f64 = 0.366_025_403_784_438_6; //(sqrt(3) - 1) / 2
const UNSKEW: f64 = 0.211_324_865_405_187_1; //(3 - sqrt(3)) / 6

const GRADIENTS: [[f64; 2]; 12] = [[1.0, 1.0], [-1.0, 1.0], [1.0, -1.0], [-1.0, -1.0], [1.0, 0.0], [-1.0, 0.0], [1.0, 0.0], [-1.0, 0.0], [0.0, 1.0], [0.0, -1.0], [0.0, 1.0], [0.0, -1.0]];

/// 2D simplex noise.
#[derive(Clone)]
pub struct Simplex {
    permutations: [u8; 512],
    offset_x: f64,
    offset_z: f64,
}

impl Simplex {
    pub fn new(random: &mut JavaRandom) -> Self {
        let offset_x = random.next_double() * 256.0;
        let offset_z = random.next_double() * 256.0;
        let _offset_y = random.next_double() * 256.0;

        Simplex { permutations: shuffled(random), offset_x, offset_z }
    }

    #[inline]
    fn p(&self, index: i32) -> i32 {
        self.permutations[(index & 511) as usize] as i32
    }

    #[inline]
    fn corner(&self, gradient: i32, x: f64, z: f64) -> f64 {
        let t = 0.5 - x * x - z * z;

        if t < 0.0 {
            return 0.0;
        }

        let t = t * t;
        let gradient = GRADIENTS[gradient as usize];
        t * t * (gradient[0] * x + gradient[1] * z)
    }

    pub fn sample(&self, x: f64, z: f64) -> f64 {
        let skew = (x + z) * SKEW;
        let i = floor(x + skew);
        let j = floor(z + skew);
        let unskew = (i + j) as f64 * UNSKEW;
        let x0 = x - (i as f64 - unskew);
        let z0 = z - (j as f64 - unskew);
        let (i1, j1) = if x0 > z0 { (1, 0) } else { (0, 1) };
        let x1 = x0 - i1 as f64 + UNSKEW;
        let z1 = z0 - j1 as f64 + UNSKEW;
        let x2 = x0 - 1.0 + 2.0 * UNSKEW;
        let z2 = z0 - 1.0 + 2.0 * UNSKEW;
        let (ii, jj) = (i & 255, j & 255);

        let g0 = self.p(ii + self.p(jj)) % 12;
        let g1 = self.p(ii + i1 + self.p(jj + j1)) % 12;
        let g2 = self.p(ii + 1 + self.p(jj + 1)) % 12;

        70.0 * (self.corner(g0, x0, z0) + self.corner(g1, x1, z1) + self.corner(g2, x2, z2))
    }

    #[allow(clippy::too_many_arguments)]
    fn add(&self, out: &mut [f64], x: f64, z: f64, sx: usize, sz: usize, scale_x: f64, scale_z: f64, amplitude: f64) {
        let mut index = 0;

        for i in 0..sx {
            let fx = (x + i as f64) * scale_x + self.offset_x;

            for k in 0..sz {
                let fz = (z + k as f64) * scale_z + self.offset_z;
                out[index] += self.sample(fx, fz) * amplitude;
                index += 1;
            }
        }
    }
}

impl Simplex {
    /// 2D simplex the way 1.8 has it, flooring 0 and below one lower, as it does.
    pub fn sample_legacy(&self, x: f64, z: f64) -> f64 {
        let sqrt3 = 3f64.sqrt();
        let skew_factor = 0.5 * (sqrt3 - 1.0);
        let unskew_factor = (3.0 - sqrt3) / 6.0;
        let fast_floor = |value: f64| if value > 0.0 { value as i32 } else { value as i32 - 1 };

        let skew = (x + z) * skew_factor;
        let i = fast_floor(x + skew);
        let j = fast_floor(z + skew);
        let unskew = (i + j) as f64 * unskew_factor;
        let x0 = x - (i as f64 - unskew);
        let z0 = z - (j as f64 - unskew);
        let (i1, j1) = if x0 > z0 { (1, 0) } else { (0, 1) };
        let x1 = x0 - i1 as f64 + unskew_factor;
        let z1 = z0 - j1 as f64 + unskew_factor;
        let x2 = x0 - 1.0 + 2.0 * unskew_factor;
        let z2 = z0 - 1.0 + 2.0 * unskew_factor;
        let (ii, jj) = (i & 255, j & 255);

        let g0 = self.p(ii + self.p(jj)) % 12;
        let g1 = self.p(ii + i1 + self.p(jj + j1)) % 12;
        let g2 = self.p(ii + 1 + self.p(jj + 1)) % 12;

        70.0 * (self.corner(g0, x0, z0) + self.corner(g1, x1, z1) + self.corner(g2, x2, z2))
    }
}

/// Simplex octaves the way 1.8 stacks them for its surface and colour noise: each octave half
/// the frequency of the last and twice the strength.
#[derive(Clone)]
pub struct LegacySimplexOctaves {
    octaves: Vec<Simplex>,
}

impl LegacySimplexOctaves {
    pub fn new(random: &mut JavaRandom, count: usize) -> Self {
        LegacySimplexOctaves { octaves: (0..count).map(|_| Simplex::new(random)).collect() }
    }

    /// One point. The game leaves the offsets out here.
    pub fn sample(&self, x: f64, z: f64) -> f64 {
        let mut total = 0.0;
        let mut frequency = 1.0;

        for octave in &self.octaves {
            total += octave.sample_legacy(x * frequency, z * frequency) / frequency;
            frequency /= 2.0;
        }

        total
    }

    /// An sx * sz grid, index x + z * sx.
    #[allow(clippy::too_many_arguments)]
    pub fn grid(&self, x: f64, z: f64, sx: usize, sz: usize, scale_x: f64, scale_z: f64, persistence: f64) -> Vec<f64> {
        let mut out = vec![0.0; sx * sz];
        let (mut d0, mut d1) = (1.0, 1.0);

        for octave in &self.octaves {
            let amplitude = 0.55 / d1;
            let (step_x, step_z) = (scale_x * d0 * d1, scale_z * d0 * d1);
            let mut index = 0;

            for k in 0..sz {
                let fz = (z + k as f64) * step_z + octave.offset_z;

                for i in 0..sx {
                    let fx = (x + i as f64) * step_x + octave.offset_x;
                    out[index] += octave.sample_legacy(fx, fz) * amplitude;
                    index += 1;
                }
            }

            d0 *= persistence;
            d1 *= 0.5;
        }

        out
    }
}

/// Simplex noise in octaves, the way the classic climate maps use it.
#[derive(Clone)]
pub struct OctaveSimplex {
    octaves: Vec<Simplex>,
}

impl OctaveSimplex {
    pub fn new(random: &mut JavaRandom, count: usize) -> Self {
        OctaveSimplex { octaves: (0..count).map(|_| Simplex::new(random)).collect() }
    }

    /// An sx * sz grid, laid out x then z. Each octave is `persistence` times the frequency of
    /// the last and half its strength.
    #[allow(clippy::too_many_arguments)]
    pub fn grid(&self, x: f64, z: f64, sx: usize, sz: usize, scale_x: f64, scale_z: f64, persistence: f64) -> Vec<f64> {
        let mut out = vec![0.0; sx * sz];
        let (scale_x, scale_z) = (scale_x / 1.5, scale_z / 1.5);
        let (mut frequency, mut strength) = (1.0, 1.0);

        for octave in &self.octaves {
            octave.add(&mut out, x, z, sx, sz, scale_x * frequency, scale_z * frequency, 0.55 / strength);
            frequency *= persistence;
            strength *= 0.5;
        }

        out
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn noise_is_smooth_and_seeded() {
        let a = OctavePerlin::new(&mut JavaRandom::new(1), 8);
        let b = OctavePerlin::new(&mut JavaRandom::new(1), 8);
        let c = OctavePerlin::new(&mut JavaRandom::new(2), 8);

        let grid = a.grid(0.0, 0.0, 0.0, 5, 17, 5, 8.0, 4.0, 8.0);
        assert_eq!(grid, b.grid(0.0, 0.0, 0.0, 5, 17, 5, 8.0, 4.0, 8.0));
        assert_ne!(grid, c.grid(0.0, 0.0, 0.0, 5, 17, 5, 8.0, 4.0, 8.0));

        //neighbouring samples on a fine grid are close together
        let fine = a.grid_2d(0.0, 0.0, 32, 32, 0.01, 0.01);
        assert!(fine.windows(2).all(|pair| (pair[0] - pair[1]).abs() < 1.0));
        assert!(fine.iter().any(|value| *value != 0.0));
    }

    #[test]
    fn simplex_stays_in_range() {
        let simplex = Simplex::new(&mut JavaRandom::new(7));

        for i in 0..1000 {
            let value = simplex.sample(i as f64 * 0.37, i as f64 * -0.11);
            assert!((-1.01..=1.01).contains(&value), "{value}");
        }
    }
}