//! Worker threads for heavy jobs. The main thread hands work over and moves on, results come
//! back through completions.

use std::sync::mpsc::{self, Sender};
use std::sync::{Arc, Mutex, OnceLock};
use std::thread;

type Job = Box<dyn FnOnce() + Send + 'static>;

static POOL: OnceLock<Mutex<Sender<Job>>> = OnceLock::new();

/// Leaves one core for the main thread, there is always at least one worker.
fn worker_count() -> usize {
    thread::available_parallelism().map_or(2, |cores| cores.get()).saturating_sub(1).clamp(1, 16)
}

fn start() -> Mutex<Sender<Job>> {
    let (sender, receiver) = mpsc::channel::<Job>();
    let receiver = Arc::new(Mutex::new(receiver));

    for number in 0..worker_count() {
        let receiver = Arc::clone(&receiver);

        thread::Builder::new()
            .name(format!("raspberry worker {number}"))
            .spawn(move || loop {
                let job = match receiver.lock().unwrap_or_else(|poisoned| poisoned.into_inner()).recv() {
                    Ok(job) => job,
                    Err(_) => return,
                };

                //a job that panics only loses itself, the worker keeps going
                let _ = std::panic::catch_unwind(std::panic::AssertUnwindSafe(job));
            })
            .expect("could not start a worker thread");
    }

    Mutex::new(sender)
}

pub fn spawn(job: impl FnOnce() + Send + 'static) {
    let sender = POOL.get_or_init(start).lock().unwrap_or_else(|poisoned| poisoned.into_inner());
    let _ = sender.send(Box::new(job));
}