//! The native half of RaspberryX. PHP loads this library through FFI and calls the functions
//! below; heavy work runs on worker threads, and PHP collects what finished with rx_poll()
//! every tick. src/raspberry/native/Native.php has the matching declarations.
//!
//! Chunks live here. PHP holds a handle to each and reads and writes blocks through it.
//!
//! Nothing here may unwind into PHP, so every function catches panics.

pub mod biome;
pub mod chunk;
pub mod completions;
pub mod encode;
pub mod generator;
pub mod noise;
pub mod pool;
pub mod random;

use std::collections::HashMap;
use std::panic::{catch_unwind, AssertUnwindSafe};
use std::ptr;
use std::sync::atomic::{AtomicU64, Ordering};
use std::sync::{Arc, Mutex, MutexGuard};

use chunk::{Chunk, Storage, SubChunk, MAX_LAYERS, SIZE, SUBCHUNKS};

/// Bumped whenever a function below changes, PHP refuses a library it does not know.
pub const ABI_VERSION: u32 = 3;

struct Entry {
    chunk: Chunk,
    /// the last batch sent for this chunk: chunk version, PHP's revision, threshold, the bytes
    sent: Option<(u64, u64, i32, Arc<Vec<u8>>)>,
}

static NEXT_HANDLE: AtomicU64 = AtomicU64::new(1);

static CHUNKS: Mutex<Option<HashMap<u64, Entry>>> = Mutex::new(None);

fn chunks() -> MutexGuard<'static, Option<HashMap<u64, Entry>>> {
    CHUNKS.lock().unwrap_or_else(|poisoned| poisoned.into_inner())
}

/// Runs `action` on the chunk behind `handle`, `missing` when there is none.
fn with_chunk<T>(handle: u64, missing: T, action: impl FnOnce(&mut Entry) -> T) -> T {
    let result = catch_unwind(AssertUnwindSafe(|| match chunks().as_mut().and_then(|map| map.get_mut(&handle)) {
        Some(entry) => Some(action(entry)),
        None => None,
    }));

    match result {
        Ok(Some(value)) => value,
        _ => missing,
    }
}

/// Hands PHP a buffer it must give back to rx_free().
unsafe fn give(data: Vec<u8>, out: *mut *mut u8, out_length: *mut usize) {
    let boxed = data.into_boxed_slice();
    *out_length = boxed.len();
    *out = Box::into_raw(boxed) as *mut u8;
}

unsafe fn slice<'a>(data: *const u8, length: usize) -> &'a [u8] {
    if data.is_null() || length == 0 {
        &[]
    } else {
        std::slice::from_raw_parts(data, length)
    }
}

#[no_mangle]
pub extern "C" fn rx_version() -> u32 {
    ABI_VERSION
}

/// Frees a buffer this library handed over.
#[no_mangle]
pub unsafe extern "C" fn rx_free(data: *mut u8, length: usize) {
    if !data.is_null() {
        let _ = catch_unwind(|| drop(Box::from_raw(ptr::slice_from_raw_parts_mut(data, length))));
    }
}

/// Takes one piece of finished work: 1 and its ID and result, or 0 when nothing is waiting.
#[no_mangle]
pub unsafe extern "C" fn rx_poll(id: *mut u64, out: *mut *mut u8, out_length: *mut usize) -> i32 {
    match catch_unwind(completions::take) {
        Ok(Some((done, data))) => {
            *id = done;
            give(data, out, out_length);
            1
        }
        _ => 0,
    }
}

/// A new chunk of air with one biome. `air` is air's state ID.
#[no_mangle]
pub extern "C" fn rx_chunk_new(air: u32, biome: u32) -> u64 {
    catch_unwind(|| {
        let handle = NEXT_HANDLE.fetch_add(1, Ordering::Relaxed);
        chunks().get_or_insert_with(HashMap::new).insert(handle, Entry { chunk: Chunk::new(air, biome), sent: None });
        handle
    })
    .unwrap_or(0)
}

#[no_mangle]
pub extern "C" fn rx_chunk_free(handle: u64) {
    let _ = catch_unwind(|| chunks().as_mut().map(|map| map.remove(&handle)));
}

/// The state ID at local x z and world y. Air for anything outside the chunk.
#[no_mangle]
pub extern "C" fn rx_chunk_get_block(handle: u64, x: u32, y: i32, z: u32, layer: u32) -> u32 {
    with_chunk(handle, 0, |entry| entry.chunk.get_block(x as usize, y, z as usize, layer as usize))
}

#[no_mangle]
pub extern "C" fn rx_chunk_set_block(handle: u64, x: u32, y: i32, z: u32, layer: u32, state: u32) -> i32 {
    with_chunk(handle, 0, |entry| entry.chunk.set_block(x as usize, y, z as usize, layer as usize, state) as i32)
}

#[no_mangle]
pub extern "C" fn rx_chunk_get_biome(handle: u64, x: u32, y: i32, z: u32) -> u32 {
    with_chunk(handle, 0, |entry| entry.chunk.get_biome(x as usize, y, z as usize))
}

#[no_mangle]
pub extern "C" fn rx_chunk_set_biome(handle: u64, x: u32, y: i32, z: u32, biome: u32) -> i32 {
    with_chunk(handle, 0, |entry| entry.chunk.set_biome(x as usize, y, z as usize, biome) as i32)
}

/// Sets every block of the chunk from min_y to max_y.
#[no_mangle]
pub extern "C" fn rx_chunk_fill(handle: u64, min_y: i32, max_y: i32, layer: u32, state: u32) {
    with_chunk(handle, (), |entry| entry.chunk.fill(min_y, max_y, layer as usize, state));
}

#[no_mangle]
pub extern "C" fn rx_chunk_fill_biome(handle: u64, biome: u32) {
    with_chunk(handle, (), |entry| entry.chunk.fill_biome(biome));
}

/// The highest sub-chunk Y (-4 to 19) that holds blocks, -128 when the chunk is all air.
#[no_mangle]
pub extern "C" fn rx_chunk_highest_subchunk(handle: u64) -> i32 {
    with_chunk(handle, -128, |entry| entry.chunk.highest_subchunk().map_or(-128, |sub| sub as i32 + chunk::MIN_SUBCHUNK))
}

#[no_mangle]
pub extern "C" fn rx_chunk_collect_garbage(handle: u64) {
    with_chunk(handle, (), |entry| entry.chunk.collect_garbage());
}

/// Writes a storage as: u32 palette count, the palette (u32 each), then 8192 bytes of u16
/// offsets when the palette has more than one entry.
fn export_storage(out: &mut Vec<u8>, storage: &Storage) {
    out.extend_from_slice(&(storage.palette.len() as u32).to_le_bytes());

    for value in &storage.palette {
        out.extend_from_slice(&value.to_le_bytes());
    }

    if storage.palette.len() > 1 {
        for offset in storage.offsets() {
            out.extend_from_slice(&offset.to_le_bytes());
        }
    }
}

fn import_storage(data: &[u8], offset: &mut usize) -> Option<Storage> {
    let mut read = |length: usize| -> Option<&[u8]> {
        let bytes = data.get(*offset..*offset + length)?;
        *offset += length;
        Some(bytes)
    };

    let count = u32::from_le_bytes(read(4)?.try_into().ok()?) as usize;

    if count == 0 || count > SIZE {
        return None;
    }

    let palette: Vec<u32> = read(count * 4)?.chunks_exact(4).map(|bytes| u32::from_le_bytes(bytes.try_into().unwrap())).collect();

    if count == 1 {
        return Some(Storage { palette, indices: None });
    }

    let indices: Box<[u16]> = read(SIZE * 2)?.chunks_exact(2).map(|bytes| u16::from_le_bytes([bytes[0], bytes[1]])).collect();

    if indices.iter().any(|index| *index as usize >= count) {
        return None;
    }

    Some(Storage { palette, indices: Some(indices) })
}

/// The whole chunk for saving, unused palette entries dropped first. Per sub-chunk, bottom to
/// top: a layer count byte, each layer's storage, then the biome storage.
#[no_mangle]
pub unsafe extern "C" fn rx_chunk_export(handle: u64, out: *mut *mut u8, out_length: *mut usize) -> i32 {
    let data = with_chunk(handle, None, |entry| {
        entry.chunk.collect_garbage();
        let mut data = Vec::with_capacity(SUBCHUNKS * 16);

        for subchunk in &entry.chunk.subchunks {
            data.push(subchunk.layers.len() as u8);

            for layer in &subchunk.layers {
                export_storage(&mut data, layer);
            }

            export_storage(&mut data, &subchunk.biomes);
        }

        Some(data)
    });

    match data {
        Some(data) => {
            give(data, out, out_length);
            1
        }
        None => 0,
    }
}

/// Replaces the chunk's content with what rx_chunk_export() wrote. 0 when the data is damaged,
/// the chunk is left as it was then.
#[no_mangle]
pub unsafe extern "C" fn rx_chunk_import(handle: u64, data: *const u8, length: usize) -> i32 {
    let data = slice(data, length);

    with_chunk(handle, 0, |entry| {
        let mut offset = 0;
        let mut subchunks = Vec::with_capacity(SUBCHUNKS);

        for _ in 0..SUBCHUNKS {
            let Some(&count) = data.get(offset) else { return 0 };
            offset += 1;

            if count == 0 || count as usize > MAX_LAYERS {
                return 0;
            }

            let mut layers = Vec::with_capacity(count as usize);

            for _ in 0..count {
                let Some(storage) = import_storage(data, &mut offset) else { return 0 };
                layers.push(storage);
            }

            let Some(biomes) = import_storage(data, &mut offset) else { return 0 };
            subchunks.push(Arc::new(SubChunk { layers, biomes }));
        }

        if offset != data.len() {
            return 0;
        }

        entry.chunk.subchunks = subchunks;
        entry.chunk.version += 1;
        1
    })
}

/// Starts encoding the chunk at x z into a compressed LevelChunk batch (see encode.rs).
/// `extra` is the block entities' NBT, `revision` changes whenever PHP changed something in
/// the chunk, so a batch already made for the same revision is reused. Gives back the ID the
/// batch comes with, 0 when there is no such chunk.
#[no_mangle]
pub unsafe extern "C" fn rx_chunk_encode(handle: u64, chunk_x: i32, chunk_z: i32, revision: u64, extra: *const u8, extra_length: usize, threshold: i32) -> u64 {
    let extra = slice(extra, extra_length).to_vec();

    let snapshot = with_chunk(handle, None, |entry| {
        let version = entry.chunk.version;

        match &entry.sent {
            Some((sent_version, sent_revision, sent_threshold, batch)) if *sent_version == version && *sent_revision == revision && *sent_threshold == threshold => Some(Err(Arc::clone(batch))),
            _ => Some(Ok(entry.chunk.clone())),
        }
    });

    let id = completions::next_id();

    match snapshot {
        None => 0,
        Some(Err(batch)) => {
            completions::complete(id, batch.as_ref().clone());
            id
        }
        Some(Ok(chunk)) => {
            pool::spawn(move || {
                let batch = Arc::new(encode::level_chunk_batch(chunk_x, chunk_z, &encode::chunk_payload(&chunk, &extra), threshold));

                with_chunk(handle, (), |entry| {
                    if entry.chunk.version == chunk.version {
                        entry.sent = Some((chunk.version, revision, threshold, Arc::clone(&batch)));
                    }
                });

                completions::complete(id, batch.as_ref().clone());
            });

            id
        }
    }
}

/// Copies the blocks and biomes of one chunk into another, cheaply: the sub-chunks are shared
/// until either is written to.
#[no_mangle]
pub extern "C" fn rx_chunk_copy(from: u64, to: u64) -> i32 {
    let Some(subchunks) = with_chunk(from, None, |entry| Some(entry.chunk.subchunks.clone())) else { return 0 };

    with_chunk(to, 0, |entry| {
        entry.chunk.subchunks = subchunks;
        entry.chunk.version += 1;
        1
    })
}

/// The block states generators use, one per line, in the order rx_old_generate() wants their
/// IDs in.
#[no_mangle]
pub unsafe extern "C" fn rx_generator_states(out: *mut *mut u8, out_length: *mut usize) {
    give(generator::blocks::STATES.join("\n").into_bytes(), out, out_length);
}

fn adopt(chunk: Chunk) -> u64 {
    let handle = NEXT_HANDLE.fetch_add(1, Ordering::Relaxed);
    chunks().get_or_insert_with(HashMap::new).insert(handle, Entry { chunk, sent: None });
    handle
}

/// Starts making a whole Old world on a worker. Its result is the spawn (three i32) and then
/// a handle (u64) for each of the 256 chunks, x * 16 + z, which belong to PHP from then on.
#[no_mangle]
pub unsafe extern "C" fn rx_old_generate(seed: i64, states: *const u32, count: usize) -> u64 {
    if states.is_null() || count != generator::blocks::count() {
        return 0;
    }

    let states = std::slice::from_raw_parts(states, count).to_vec();
    let id = completions::next_id();

    pool::spawn(move || {
        let generated = generator::old::generate(seed, &states);
        let mut out = Vec::with_capacity(12 + generated.chunks.len() * 8);

        for value in [generated.spawn.0, generated.spawn.1, generated.spawn.2] {
            out.extend_from_slice(&value.to_le_bytes());
        }

        for chunk in generated.chunks {
            out.extend_from_slice(&adopt(chunk).to_le_bytes());
        }

        completions::complete(id, out);
    });

    id
}

/// Fills a chunk outside an Old world: barriers where it borders the world, air elsewhere.
#[no_mangle]
pub extern "C" fn rx_old_border(handle: u64, chunk_x: i32, chunk_z: i32, barrier: u32) {
    with_chunk(handle, (), |entry| generator::old::border(&mut entry.chunk, chunk_x, chunk_z, barrier));
}

/// A pre-normal generator and the state IDs of its blocks.
struct PreNormalEntry {
    generator: generator::pre_normal::PreNormal,
    states: Vec<u32>,
}

static PRE_NORMAL: Mutex<Option<HashMap<u64, Arc<PreNormalEntry>>>> = Mutex::new(None);

fn pre_normal(handle: u64) -> Option<Arc<PreNormalEntry>> {
    PRE_NORMAL.lock().unwrap_or_else(|poisoned| poisoned.into_inner()).as_ref().and_then(|map| map.get(&handle).cloned())
}

/// Runs a job on a worker and always completes it: with what it made, or with nothing when
/// it failed, so PHP never waits forever.
fn spawn_job(job: impl FnOnce() -> Vec<u8> + Send + 'static) -> u64 {
    let id = completions::next_id();

    pool::spawn(move || {
        let result = catch_unwind(AssertUnwindSafe(job)).unwrap_or_default();
        completions::complete(id, result);
    });

    id
}

/// Makes a pre-normal generator for a seed, with the state ID of each block in the order of
/// rx_generator_states(). 0 when the states do not fit.
#[no_mangle]
pub unsafe extern "C" fn rx_pre_normal_new(seed: i64, states: *const u32, count: usize) -> u64 {
    if states.is_null() || count != generator::blocks::count() {
        return 0;
    }

    let states = std::slice::from_raw_parts(states, count).to_vec();

    catch_unwind(|| {
        let entry = PreNormalEntry { generator: generator::pre_normal::PreNormal::new(seed), states };
        let handle = NEXT_HANDLE.fetch_add(1, Ordering::Relaxed);
        PRE_NORMAL.lock().unwrap_or_else(|poisoned| poisoned.into_inner()).get_or_insert_with(HashMap::new).insert(handle, Arc::new(entry));
        handle
    })
    .unwrap_or(0)
}

/// Drops a generator. Jobs still running finish with the copy they hold.
#[no_mangle]
pub extern "C" fn rx_pre_normal_free(handle: u64) {
    if let Some(map) = PRE_NORMAL.lock().unwrap_or_else(|poisoned| poisoned.into_inner()).as_mut() {
        map.remove(&handle);
    }
}

/// Starts making a chunk on a worker. Its result is the handle (u64) of a new chunk that
/// belongs to PHP from then on, or nothing when it failed. 0 for an unknown generator.
#[no_mangle]
pub extern "C" fn rx_pre_normal_chunk(handle: u64, chunk_x: i32, chunk_z: i32) -> u64 {
    let Some(entry) = pre_normal(handle) else { return 0 };

    spawn_job(move || {
        let chunk = entry.generator.chunk(chunk_x, chunk_z, &entry.states);
        adopt(chunk).to_le_bytes().to_vec()
    })
}

/// Starts looking for the spawn on a worker. Its result is X, Y and Z (three i32).
#[no_mangle]
pub extern "C" fn rx_pre_normal_spawn(handle: u64) -> u64 {
    let Some(entry) = pre_normal(handle) else { return 0 };

    spawn_job(move || {
        let (x, y, z) = entry.generator.spawn();
        [x, y, z].iter().flat_map(|value| value.to_le_bytes()).collect()
    })
}