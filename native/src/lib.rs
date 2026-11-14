//! The native half of RaspberryX. PHP loads this library through FFI and calls the functions
//! below. Heavy work runs on worker threads, and PHP collects what finished with rx_poll()
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
pub mod growth;
pub mod light;
pub mod mob;
pub mod noise;
pub mod pool;
pub mod random;

use std::ptr;
use std::collections::HashMap;
use std::panic::{catch_unwind, AssertUnwindSafe};
use std::sync::atomic::{AtomicBool, AtomicU64, Ordering};
use std::sync::{Arc, Mutex, MutexGuard};

use chunk::{Chunk, Storage, SubChunk, MAX_LAYERS, SIZE, SUBCHUNKS};

/// Bumped whenever a function below changes, PHP refuses a library it does not know.
pub const ABI_VERSION: u32 = 7;

struct Entry {
    chunk: Chunk,
    sent: Option<(u64, u64, i32, Arc<Vec<u8>>)>,
}

static NEXT_HANDLE: AtomicU64 = AtomicU64::new(1);

static CHUNKS: Mutex<Option<HashMap<u64, Entry>>> = Mutex::new(None);

fn chunks() -> MutexGuard<'static, Option<HashMap<u64, Entry>>> {
    CHUNKS.lock().unwrap_or_else(|poisoned| poisoned.into_inner())
}

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

#[no_mangle]
pub unsafe extern "C" fn rx_free(data: *mut u8, length: usize) {
    if !data.is_null() {
        let _ = catch_unwind(|| drop(Box::from_raw(ptr::slice_from_raw_parts_mut(data, length))));
    }
}

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

#[no_mangle]
pub extern "C" fn rx_chunk_fill(handle: u64, min_y: i32, max_y: i32, layer: u32, state: u32) {
    with_chunk(handle, (), |entry| entry.chunk.fill(min_y, max_y, layer as usize, state));
}

#[no_mangle]
pub extern "C" fn rx_chunk_fill_biome(handle: u64, biome: u32) {
    with_chunk(handle, (), |entry| entry.chunk.fill_biome(biome));
}

#[no_mangle]
pub extern "C" fn rx_chunk_highest_subchunk(handle: u64) -> i32 {
    with_chunk(handle, -128, |entry| entry.chunk.highest_subchunk().map_or(-128, |sub| sub as i32 + chunk::MIN_SUBCHUNK))
}

#[no_mangle]
pub unsafe extern "C" fn rx_chunk_find(handle: u64, states: *const u32, count: usize, out: *mut *mut u8, out_length: *mut usize) -> i32 {
    let states = if states.is_null() || count == 0 { &[][..] } else { std::slice::from_raw_parts(states, count) };
    let found = with_chunk(handle, None, |entry| Some(entry.chunk.find(states)));

    match found {
        Some(found) => {
            let mut data = Vec::with_capacity(found.len() * 4);

            for (x, y, z) in found {
                data.push(x);
                data.push(z);
                data.extend_from_slice(&(y as i16).to_le_bytes());
            }

            give(data, out, out_length);
            1
        }
        None => 0,
    }
}

#[no_mangle]
pub extern "C" fn rx_chunk_collect_garbage(handle: u64) {
    with_chunk(handle, (), |entry| entry.chunk.collect_garbage());
}

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

#[no_mangle]
pub extern "C" fn rx_chunk_copy(from: u64, to: u64) -> i32 {
    let Some(subchunks) = with_chunk(from, None, |entry| Some(entry.chunk.subchunks.clone())) else { return 0 };

    with_chunk(to, 0, |entry| {
        entry.chunk.subchunks = subchunks;
        entry.chunk.version += 1;
        1
    })
}

#[no_mangle]
pub unsafe extern "C" fn rx_generator_states(out: *mut *mut u8, out_length: *mut usize) {
    give(generator::blocks::STATES.join("\n").into_bytes(), out, out_length);
}

fn adopt(chunk: Chunk) -> u64 {
    let handle = NEXT_HANDLE.fetch_add(1, Ordering::Relaxed);
    chunks().get_or_insert_with(HashMap::new).insert(handle, Entry { chunk, sent: None });
    handle
}

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

#[no_mangle]
pub extern "C" fn rx_old_border(handle: u64, chunk_x: i32, chunk_z: i32, barrier: u32) {
    with_chunk(handle, (), |entry| generator::old::border(&mut entry.chunk, chunk_x, chunk_z, barrier));
}

struct PreNormalEntry {
    generator: generator::pre_normal::PreNormal,
    states: Vec<u32>,
}

static PRE_NORMAL: Mutex<Option<HashMap<u64, Arc<PreNormalEntry>>>> = Mutex::new(None);

fn pre_normal(handle: u64) -> Option<Arc<PreNormalEntry>> {
    PRE_NORMAL.lock().unwrap_or_else(|poisoned| poisoned.into_inner()).as_ref().and_then(|map| map.get(&handle).cloned())
}

fn spawn_job(job: impl FnOnce() -> Vec<u8> + Send + 'static) -> u64 {
    let id = completions::next_id();

    pool::spawn(move || {
        let result = catch_unwind(AssertUnwindSafe(job)).unwrap_or_default();
        completions::complete(id, result);
    });

    id
}

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

#[no_mangle]
pub extern "C" fn rx_pre_normal_free(handle: u64) {
    if let Some(map) = PRE_NORMAL.lock().unwrap_or_else(|poisoned| poisoned.into_inner()).as_mut() {
        map.remove(&handle);
    }
}

#[no_mangle]
pub extern "C" fn rx_pre_normal_chunk(handle: u64, chunk_x: i32, chunk_z: i32) -> u64 {
    let Some(entry) = pre_normal(handle) else { return 0 };

    spawn_job(move || {
        let chunk = entry.generator.chunk(chunk_x, chunk_z, &entry.states);
        adopt(chunk).to_le_bytes().to_vec()
    })
}

#[no_mangle]
pub extern "C" fn rx_pre_normal_spawn(handle: u64) -> u64 {
    let Some(entry) = pre_normal(handle) else { return 0 };

    spawn_job(move || {
        let (x, y, z) = entry.generator.spawn();
        [x, y, z].iter().flat_map(|value| value.to_le_bytes()).collect()
    })
}

struct MobWorld {
    state: Arc<Mutex<mob::World>>,
    chunks: HashMap<(i32, i32), u64>,
    busy: Arc<AtomicBool>,
}

static MOB_WORLDS: Mutex<Option<HashMap<u64, MobWorld>>> = Mutex::new(None);

fn mob_worlds() -> MutexGuard<'static, Option<HashMap<u64, MobWorld>>> {
    MOB_WORLDS.lock().unwrap_or_else(|poisoned| poisoned.into_inner())
}

#[no_mangle]
pub extern "C" fn rx_mobs_new(seed: i64) -> u64 {
    catch_unwind(|| {
        let handle = NEXT_HANDLE.fetch_add(1, Ordering::Relaxed);
        let world = MobWorld { state: Arc::new(Mutex::new(mob::World::new(seed))), chunks: HashMap::new(), busy: Arc::new(AtomicBool::new(false)) };

        mob_worlds().get_or_insert_with(HashMap::new).insert(handle, world);
        handle
    })
    .unwrap_or(0)
}

#[no_mangle]
pub extern "C" fn rx_mobs_free(world: u64) {
    let _ = catch_unwind(|| mob_worlds().as_mut().map(|map| map.remove(&world)));
}

#[no_mangle]
pub extern "C" fn rx_mobs_chunk(world: u64, chunk_x: i32, chunk_z: i32, chunk: u64) {
    let _ = catch_unwind(|| {
        if let Some(entry) = mob_worlds().as_mut().and_then(|map| map.get_mut(&world)) {
            if chunk == 0 {
                entry.chunks.remove(&(chunk_x, chunk_z));
            } else {
                entry.chunks.insert((chunk_x, chunk_z), chunk);
            }
        }
    });
}

#[no_mangle]
pub unsafe extern "C" fn rx_mobs_blocks(states: *const u32, flags: *const u16, count: usize) {
    if states.is_null() || flags.is_null() || count == 0 {
        return;
    }

    let states = std::slice::from_raw_parts(states, count);
    let flags = std::slice::from_raw_parts(flags, count);
    let _ = catch_unwind(|| mob::blocks::learn(states.iter().copied().zip(flags.iter().copied())));
}

#[no_mangle]
pub unsafe extern "C" fn rx_mobs_tick(world: u64, input: *const u8, length: usize) -> u64 {
    let input = slice(input, length);

    catch_unwind(AssertUnwindSafe(|| {
        let worlds = mob_worlds();
        let Some(entry) = worlds.as_ref().and_then(|map| map.get(&world)) else { return 0 };

        if entry.busy.load(Ordering::Acquire) {
            return 0;
        }

        let state = Arc::clone(&entry.state);
        let needed = {
            let mut mobs = state.lock().unwrap_or_else(|poisoned| poisoned.into_inner());
            let _ = mobs.apply(input);
            mobs.chunks_needed()
        };

        let mut snapshot = HashMap::with_capacity(needed.len());

        if let Some(all) = chunks().as_ref() {
            for position in needed {
                if let Some(found) = entry.chunks.get(&position).and_then(|handle| all.get(handle)) {
                    snapshot.insert(position, found.chunk.clone());
                }
            }
        }

        let view = mob::blocks::View::new(snapshot, mob::blocks::table());
        let busy = Arc::clone(&entry.busy);
        let id = completions::next_id();

        busy.store(true, Ordering::Release);

        pool::spawn(move || {
            let result = catch_unwind(AssertUnwindSafe(|| state.lock().unwrap_or_else(|poisoned| poisoned.into_inner()).tick(&view)));

            busy.store(false, Ordering::Release);
            completions::complete(id, result.unwrap_or_else(|_| vec![0; 12]));
        });

        id
    }))
    .unwrap_or(0)
}

struct LightEntry {
    light: light::LightWorld,
    chunks: HashMap<(i32, i32), u64>,
}

static LIGHT_WORLDS: Mutex<Option<HashMap<u64, LightEntry>>> = Mutex::new(None);

fn light_worlds() -> MutexGuard<'static, Option<HashMap<u64, LightEntry>>> {
    LIGHT_WORLDS.lock().unwrap_or_else(|poisoned| poisoned.into_inner())
}

fn with_light<T>(world: u64, missing: T, action: impl FnOnce(&mut LightEntry) -> T) -> T {
    let result = catch_unwind(AssertUnwindSafe(|| light_worlds().as_mut().and_then(|map| map.get_mut(&world)).map(action)));

    match result {
        Ok(Some(value)) => value,
        _ => missing,
    }
}

#[no_mangle]
pub extern "C" fn rx_light_new() -> u64 {
    catch_unwind(|| {
        let handle = NEXT_HANDLE.fetch_add(1, Ordering::Relaxed);
        light_worlds().get_or_insert_with(HashMap::new).insert(handle, LightEntry { light: light::LightWorld::new(), chunks: HashMap::new() });
        handle
    })
    .unwrap_or(0)
}

#[no_mangle]
pub extern "C" fn rx_light_free(world: u64) {
    let _ = catch_unwind(|| light_worlds().as_mut().map(|map| map.remove(&world)));
}

#[no_mangle]
pub unsafe extern "C" fn rx_light_blocks(states: *const u32, values: *const u8, count: usize) {
    if states.is_null() || values.is_null() || count == 0 {
        return;
    }

    let states = std::slice::from_raw_parts(states, count);
    let values = std::slice::from_raw_parts(values, count);
    let _ = catch_unwind(|| light::learn(states.iter().copied().zip(values.iter().copied())));
}

#[no_mangle]
pub extern "C" fn rx_light_add_chunk(world: u64, chunk_x: i32, chunk_z: i32, chunk: u64) -> i32 {
    with_light(world, 0, |entry| {
        let all = chunks();
        let Some(found) = all.as_ref().and_then(|map| map.get(&chunk)) else { return 0 };

        entry.light.add_chunk(chunk_x, chunk_z, &found.chunk);
        entry.chunks.insert((chunk_x, chunk_z), chunk);
        1
    })
}

#[no_mangle]
pub extern "C" fn rx_light_remove_chunk(world: u64, chunk_x: i32, chunk_z: i32) {
    with_light(world, (), |entry| {
        entry.light.remove_chunk(chunk_x, chunk_z);
        entry.chunks.remove(&(chunk_x, chunk_z));
    });
}

#[no_mangle]
pub extern "C" fn rx_light_update(world: u64, x: i32, y: i32, z: i32) {
    with_light(world, (), |entry| {
        let Some(handle) = entry.chunks.get(&(x >> 4, z >> 4)) else { return };
        let props = {
            let all = chunks();
            let Some(found) = all.as_ref().and_then(|map| map.get(handle)) else { return };
            light::props_at(&found.chunk, (x & 15) as usize, y, (z & 15) as usize)
        };

        entry.light.update(x, y, z, props);
    });
}

#[no_mangle]
pub extern "C" fn rx_light_get(world: u64, x: i32, y: i32, z: i32) -> i32 {
    with_light(world, -1, |entry| entry.light.get(x, y, z).map_or(-1, |(block, sky)| (block | sky << 4) as i32))
}

#[no_mangle]
pub extern "C" fn rx_light_sky_bottom(world: u64, x: i32, z: i32) -> i32 {
    with_light(world, i32::MIN, |entry| entry.light.sky_bottom(x, z).unwrap_or(i32::MIN))
}

#[no_mangle]
pub unsafe extern "C" fn rx_random_tick_blocks(states: *const u32, count: usize) {
    let states = if states.is_null() || count == 0 { &[][..] } else { std::slice::from_raw_parts(states, count) };
    let _ = catch_unwind(|| growth::set_ticking(states.iter().copied()));
}

#[no_mangle]
pub unsafe extern "C" fn rx_random_tick(handles: *const u64, count: usize, per_subchunk: u32, seed: u64, out: *mut *mut u8, out_length: *mut usize) -> i32 {
    let handles = if handles.is_null() || count == 0 { &[][..] } else { std::slice::from_raw_parts(handles, count) };

    let picked = catch_unwind(|| {
        let ticking = growth::ticking();
        let mut picker = growth::Picker::new(seed);
        let mut data = Vec::new();
        let all = chunks();
        let mut found = Vec::new();

        for (i, handle) in handles.iter().enumerate() {
            let Some(entry) = all.as_ref().and_then(|map| map.get(handle)) else { continue };
            found.clear();
            growth::pick(&entry.chunk, per_subchunk, &mut picker, &ticking, &mut found);

            for &(x, y, z, state) in &found {
                data.extend_from_slice(&(i as u16).to_le_bytes());
                data.push(x);
                data.push(z);
                data.extend_from_slice(&(y as i16).to_le_bytes());
                data.extend_from_slice(&state.to_le_bytes());
            }
        }

        data
    });

    match picked {
        Ok(data) => {
            give(data, out, out_length);
            1
        }
        Err(_) => 0,
    }
}

#[no_mangle]
pub unsafe extern "C" fn rx_tree_grow(
    kind: i32, x: i32, y: i32, z: i32, seed: i64,
    handles: *const u64, positions: *const i32, chunk_count: usize,
    cleared: *const i32, cleared_count: usize,
    states: *const u32, state_count: usize,
    soil: *const u32, soil_count: usize,
    out: *mut *mut u8, out_length: *mut usize,
) -> i32 {
    if handles.is_null() || positions.is_null() || states.is_null() || chunk_count == 0 || state_count < blocks_count() {
        return 0;
    }

    let handles = std::slice::from_raw_parts(handles, chunk_count);
    let positions = std::slice::from_raw_parts(positions, chunk_count * 2);
    let cleared = if cleared.is_null() || cleared_count == 0 { &[][..] } else { std::slice::from_raw_parts(cleared, cleared_count * 3) };
    let states = std::slice::from_raw_parts(states, state_count);
    let soil = if soil.is_null() || soil_count == 0 { &[][..] } else { std::slice::from_raw_parts(soil, soil_count) };

    let grown = catch_unwind(|| {
        let lookup: HashMap<u32, generator::blocks::Block> = states.iter().enumerate().map(|(i, state)| (*state, i as generator::blocks::Block)).collect();
        let soil: std::collections::HashSet<u32> = soil.iter().copied().collect();
        let cleared = cleared.chunks_exact(3).map(|c| (c[0], c[1], c[2])).collect();
        let all = chunks();
        let map = all.as_ref()?;
        let mut around = HashMap::new();

        for (i, handle) in handles.iter().enumerate() {
            if let Some(entry) = map.get(handle) {
                around.insert((positions[i * 2], positions[i * 2 + 1]), &entry.chunk);
            }
        }

        let mut canvas = growth::WorldCanvas::new(around, &lookup, states, &soil, cleared);

        if !growth::grow_tree(&mut canvas, kind, x, y, z, seed) {
            return None;
        }

        let mut data = Vec::new();

        for (bx, by, bz, state) in canvas.changes() {
            data.extend_from_slice(&bx.to_le_bytes());
            data.extend_from_slice(&by.to_le_bytes());
            data.extend_from_slice(&bz.to_le_bytes());
            data.extend_from_slice(&state.to_le_bytes());
        }

        Some(data)
    });

    match grown {
        Ok(Some(data)) => {
            give(data, out, out_length);
            1
        }
        _ => 0,
    }
}

fn blocks_count() -> usize {
    generator::blocks::count()
}