<?php

declare(strict_types=1);

namespace raspberry\native;

use FFI\CData;

/**
 * @method int rx_version()
 * @method void rx_free(?CData $data, int $length)
 * @method int rx_poll(?CData $id, ?CData $out, ?CData $out_length)
 * @method int rx_chunk_new(int $air, int $biome)
 * @method void rx_chunk_free(int $chunk)
 * @method int rx_chunk_get_block(int $chunk, int $x, int $y, int $z, int $layer)
 * @method int rx_chunk_set_block(int $chunk, int $x, int $y, int $z, int $layer, int $state)
 * @method int rx_chunk_get_biome(int $chunk, int $x, int $y, int $z)
 * @method int rx_chunk_set_biome(int $chunk, int $x, int $y, int $z, int $biome)
 * @method void rx_chunk_fill(int $chunk, int $min_y, int $max_y, int $layer, int $state)
 * @method void rx_chunk_fill_biome(int $chunk, int $biome)
 * @method int rx_chunk_highest_subchunk(int $chunk)
 * @method void rx_chunk_collect_garbage(int $chunk)
 * @method int rx_chunk_find(int $chunk, ?CData $states, int $count, ?CData $out, ?CData $out_length)
 * @method int rx_light_new()
 * @method void rx_light_free(int $world)
 * @method void rx_light_blocks(?CData $states, ?CData $values, int $count)
 * @method int rx_light_add_chunk(int $world, int $chunk_x, int $chunk_z, int $chunk)
 * @method void rx_light_remove_chunk(int $world, int $chunk_x, int $chunk_z)
 * @method void rx_light_update(int $world, int $x, int $y, int $z)
 * @method int rx_light_get(int $world, int $x, int $y, int $z)
 * @method int rx_light_sky_bottom(int $world, int $x, int $z)
 * @method int rx_chunk_export(int $chunk, ?CData $out, ?CData $out_length)
 * @method int rx_chunk_import(int $chunk, string|CData|null $data, int $length)
 * @method int rx_chunk_encode(int $chunk, int $chunk_x, int $chunk_z, int $revision, string|CData|null $extra, int $extra_length, int $threshold)
 * @method int rx_chunk_copy(int $from, int $to)
 * @method void rx_generator_states(?CData $out, ?CData $out_length)
 * @method int rx_old_generate(int $seed, ?CData $states, int $count)
 * @method void rx_old_border(int $chunk, int $chunk_x, int $chunk_z, int $barrier)
 * @method int rx_pre_normal_new(int $seed, ?CData $states, int $count)
 * @method void rx_pre_normal_free(int $generator)
 * @method int rx_pre_normal_chunk(int $generator, int $chunk_x, int $chunk_z)
 * @method int rx_pre_normal_spawn(int $generator)
 * @method int rx_mobs_new(int $seed)
 * @method void rx_mobs_free(int $world)
 * @method void rx_mobs_chunk(int $world, int $chunk_x, int $chunk_z, int $chunk)
 * @method void rx_mobs_blocks(?CData $states, ?CData $flags, int $count)
 * @method int rx_mobs_tick(int $world, string|CData|null $input, int $length)
 * @method void rx_random_tick_blocks(?CData $states, int $count)
 * @method int rx_random_tick(?CData $chunks, int $count, int $per_subchunk, int $seed, ?CData $out, ?CData $out_length)
 * @method int rx_tree_grow(int $kind, int $x, int $y, int $z, int $seed, ?CData $chunks, ?CData $positions, int $chunk_count, ?CData $cleared, int $cleared_count, ?CData $states, int $state_count, ?CData $soil, int $soil_count, ?CData $out, ?CData $out_length)
 * @method int rx_liquid_new()
 * @method void rx_liquid_free(int $world)
 * @method void rx_liquid_blocks(?CData $states, ?CData $kinds, ?CData $levels, ?CData $extras, int $count, ?CData $results)
 * @method int rx_liquid_add_chunk(int $world, int $chunk_x, int $chunk_z, int $chunk, int $now)
 * @method void rx_liquid_remove_chunk(int $world, int $chunk_x, int $chunk_z)
 * @method void rx_liquid_changed(int $world, int $x, int $y, int $z, int $now)
 * @method void rx_liquid_ticking(int $world, ?CData $chunks, int $count)
 * @method int rx_liquid_tick(int $world, int $now, ?CData $out, ?CData $out_length)
 * @method void rx_explosion_blocks(?CData $states, ?CData $resistances, ?CData $solids, int $count, int $air)
 * @method int rx_explode(?CData $chunks, ?CData $positions, int $chunk_count, float $x, float $y, float $z, float $radius, int $seed, ?CData $boxes, int $box_count, ?CData $exposures, ?CData $out, ?CData $out_length)
 */

interface NativeMethods{

}