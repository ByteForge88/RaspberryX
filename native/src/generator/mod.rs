//! World generators. Their blocks are numbered in blocks.rs; PHP turns those into state IDs
//! once and hands them over, after that a generator needs nothing from PHP.

pub mod blocks;
pub mod features;
pub mod old;
pub mod pre_normal;