//! Turns a chunk into the LevelChunk packet the client wants, framed as a batch and compressed,
//! ready for the transport. This is the heavy part of sending chunks and runs on workers.

use crate::chunk::{Chunk, Storage, SIZE};

const SUBCHUNK_VERSION: u8 = 9;

/// bits per entry Bedrock accepts, smallest first
const BIT_SIZES: [u32; 9] = [0, 1, 2, 3, 4, 5, 6, 8, 16];

/// written instead of a biome storage that is identical to the previous one
const BIOME_SAME_AS_PREVIOUS: u8 = 0xff;

const LEVEL_CHUNK_PACKET: u32 = 0x3a;

const COMPRESSION_DEFLATE: u8 = 0x00;
const COMPRESSION_NONE: u8 = 0xff;
const DEFLATE_LEVEL: u8 = 6;

pub struct Writer {
    pub data: Vec<u8>,
}

impl Writer {
    pub fn with_capacity(capacity: usize) -> Self {
        Writer { data: Vec::with_capacity(capacity) }
    }

    pub fn byte(&mut self, value: u8) {
        self.data.push(value);
    }

    pub fn var_uint(&mut self, mut value: u32) {
        while value >= 0x80 {
            self.data.push((value as u8 & 0x7f) | 0x80);
            value >>= 7;
        }

        self.data.push(value as u8);
    }

    pub fn var_int(&mut self, value: i32) {
        self.var_uint(((value << 1) ^ (value >> 31)) as u32);
    }

    pub fn bytes(&mut self, bytes: &[u8]) {
        self.data.extend_from_slice(bytes);
    }
}

/// A storage as it is sent: its used entries in the order they first appear, and each
/// position's offset into that list.
fn compact(storage: &Storage) -> (Vec<u32>, Option<Vec<u16>>) {
    let Some(indices) = &storage.indices else {
        return (vec![storage.palette[0]], None);
    };

    let mut remap = vec![u16::MAX; storage.palette.len()];
    let mut palette = Vec::new();
    let mut offsets = Vec::with_capacity(SIZE);

    for offset in indices.iter() {
        let old = *offset as usize;

        if remap[old] == u16::MAX {
            remap[old] = palette.len() as u16;
            palette.push(storage.palette[old]);
        }

        offsets.push(remap[old]);
    }

    if palette.len() == 1 {
        return (palette, None);
    }

    (palette, Some(offsets))
}

fn write_storage(writer: &mut Writer, palette: &[u32], offsets: Option<&[u16]>) {
    let bits = BIT_SIZES.into_iter().find(|bits| palette.len() <= 1usize << bits).expect("a palette has at most 4096 entries");

    writer.byte(((bits << 1) | 1) as u8);

    let Some(offsets) = offsets.filter(|_| bits > 0) else {
        writer.var_int(palette[0] as i32);
        return;
    };

    let per_word = (32 / bits) as usize;
    let words = SIZE.div_ceil(per_word);

    for word in 0..words {
        let mut value = 0u32;

        for slot in 0..per_word {
            if let Some(offset) = offsets.get(word * per_word + slot) {
                value |= (*offset as u32) << (slot as u32 * bits);
            }
        }

        writer.bytes(&value.to_le_bytes());
    }

    writer.var_int(palette.len() as i32);

    for value in palette {
        writer.var_int(*value as i32);
    }
}

/// The chunk data of a LevelChunk packet, `extra` (the block entities' NBT) goes at the end.
pub fn chunk_payload(chunk: &Chunk, extra: &[u8]) -> Vec<u8> {
    let mut writer = Writer::with_capacity(16 * 1024 + extra.len());

    for (index, subchunk) in chunk.subchunks.iter().enumerate() {
        writer.byte(SUBCHUNK_VERSION);
        writer.byte(subchunk.layers.len() as u8);
        writer.byte((index as i32 + crate::chunk::MIN_SUBCHUNK) as u8);

        for layer in &subchunk.layers {
            let (palette, offsets) = compact(layer);
            write_storage(&mut writer, &palette, offsets.as_deref());
        }
    }

    let mut previous: Option<u32> = None;

    for subchunk in &chunk.subchunks {
        let (palette, offsets) = compact(&subchunk.biomes);
        let uniform = offsets.is_none().then_some(palette[0]);

        if uniform.is_some() && previous == uniform {
            writer.byte(BIOME_SAME_AS_PREVIOUS);
        } else {
            write_storage(&mut writer, &palette, offsets.as_deref());
        }

        //only a uniform storage can be repeated, a mixed one breaks the run
        previous = uniform;
    }

    writer.byte(0); //border blocks
    writer.bytes(extra);

    writer.data
}

/// The LevelChunk packet for the chunk at x z, in a batch of its own, compressed the way the
/// session negotiated: `threshold` is the size from which batches are compressed, negative
/// when the session does not compress at all.
pub fn level_chunk_batch(chunk_x: i32, chunk_z: i32, payload: &[u8], threshold: i32) -> Vec<u8> {
    let mut packet = Writer::with_capacity(payload.len() + 32);
    packet.var_uint(LEVEL_CHUNK_PACKET);
    packet.var_int(chunk_x);
    packet.var_int(chunk_z);
    packet.var_int(0); //overworld
    packet.var_uint(crate::chunk::SUBCHUNKS as u32);
    packet.byte(0); //no blob cache
    packet.byte(0);
    packet.var_uint(0);
    packet.var_uint(payload.len() as u32);
    packet.bytes(payload);
    packet.byte(0); //keep the client's biomes

    let mut batch = Writer::with_capacity(packet.data.len() + 5);
    batch.var_uint(packet.data.len() as u32);
    batch.bytes(&packet.data);

    if threshold < 0 {
        return batch.data;
    }

    if (batch.data.len() as i64) < threshold as i64 {
        let mut out = Vec::with_capacity(batch.data.len() + 1);
        out.push(COMPRESSION_NONE);
        out.extend_from_slice(&batch.data);
        return out;
    }

    let mut out = vec![COMPRESSION_DEFLATE];
    out.extend_from_slice(&miniz_oxide::deflate::compress_to_vec(&batch.data, DEFLATE_LEVEL));
    out
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn var_ints() {
        let mut writer = Writer::with_capacity(16);
        writer.var_int(-1);
        writer.var_int(300);
        writer.var_uint(0x3a);
        assert_eq!(writer.data, vec![0x01, 0xd8, 0x04, 0x3a]);
    }

    #[test]
    fn compressed_batch_inflates_back() {
        let chunk = Chunk::new(1, 1);
        let payload = chunk_payload(&chunk, b"");
        let batch = level_chunk_batch(-3, 7, &payload, 1);
        assert_eq!(batch[0], COMPRESSION_DEFLATE);
        let raw = miniz_oxide::inflate::decompress_to_vec(&batch[1..]).unwrap();
        assert_eq!(raw, level_chunk_batch(-3, 7, &payload, -1));
    }

    #[test]
    fn unused_palette_entries_are_not_sent() {
        let mut chunk = Chunk::new(1, 1);
        chunk.set_block(0, -64, 0, 0, 5);
        chunk.set_block(0, -64, 0, 0, 1);
        let mut collected = chunk.clone();
        collected.collect_garbage();
        assert_eq!(chunk_payload(&chunk, b""), chunk_payload(&collected, b""));
    }
}