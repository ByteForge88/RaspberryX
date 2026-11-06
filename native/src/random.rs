//! java.util.Random, bit for bit. The classic generators were written against it, so seeds
//! give the same worlds they did there.

#[derive(Clone, Debug)]
pub struct JavaRandom {
    seed: i64,
}

const MULTIPLIER: i64 = 0x5DEECE66D;
const MASK: i64 = (1 << 48) - 1;

impl JavaRandom {
    pub fn new(seed: i64) -> Self {
        JavaRandom { seed: (seed ^ MULTIPLIER) & MASK }
    }

    pub fn set_seed(&mut self, seed: i64) {
        self.seed = (seed ^ MULTIPLIER) & MASK;
    }

    fn next(&mut self, bits: u32) -> i32 {
        self.seed = (self.seed.wrapping_mul(MULTIPLIER).wrapping_add(0xB)) & MASK;
        (self.seed >> (48 - bits)) as i32
    }

    pub fn next_int(&mut self) -> i32 {
        self.next(32)
    }

    /// A number from 0 to bound - 1. A bound of 0 or less gives 0 instead of throwing.
    pub fn next_int_bounded(&mut self, bound: i32) -> i32 {
        if bound <= 0 {
            return 0;
        }

        if bound & -bound == bound {
            return ((bound as i64 * self.next(31) as i64) >> 31) as i32;
        }

        loop {
            let bits = self.next(31);
            let value = bits % bound;

            if bits - value + (bound - 1) >= 0 {
                return value;
            }
        }
    }

    pub fn next_long(&mut self) -> i64 {
        ((self.next(32) as i64) << 32).wrapping_add(self.next(32) as i64)
    }

    pub fn next_bool(&mut self) -> bool {
        self.next(1) != 0
    }

    pub fn next_float(&mut self) -> f32 {
        self.next(24) as f32 / (1 << 24) as f32
    }

    pub fn next_double(&mut self) -> f64 {
        (((self.next(26) as i64) << 27) + self.next(27) as i64) as f64 * (1.0 / (1i64 << 53) as f64)
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn matches_java() {
        //what new Random(42) gives in Java
        assert_eq!(JavaRandom::new(42).next_int(), -1170105035);
        assert_eq!(JavaRandom::new(42).next_int_bounded(10), 0);

        let mut random = JavaRandom::new(42);
        assert_eq!(random.next_long(), -5025562857975149833);
        assert!((JavaRandom::new(42).next_double() - 0.7275636800328681).abs() < 1e-15);
    }
}
