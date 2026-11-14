//! Work finished on the worker threads waits here until PHP picks it up in its tick.

use std::collections::VecDeque;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::Mutex;

static NEXT_ID: AtomicU64 = AtomicU64::new(1);

static DONE: Mutex<VecDeque<(u64, Vec<u8>)>> = Mutex::new(VecDeque::new());

pub fn next_id() -> u64 {
    NEXT_ID.fetch_add(1, Ordering::Relaxed)
}

pub fn complete(id: u64, data: Vec<u8>) {
    lock().push_back((id, data));
}

pub fn take() -> Option<(u64, Vec<u8>)> {
    lock().pop_front()
}

fn lock() -> std::sync::MutexGuard<'static, VecDeque<(u64, Vec<u8>)>> {
    DONE.lock().unwrap_or_else(|poisoned| poisoned.into_inner())
}