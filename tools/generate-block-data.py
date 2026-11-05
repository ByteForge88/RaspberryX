#!/usr/bin/env python3
"""
Generates RaspberryX block data from Mojang's official bedrock-samples repository.

Url: https://github.com/Mojang/bedrock-samples

file metadata/vanilladata_modules/mojang-blocks.json.

Outputs:
  src/raspberry/block/BlockIds.php          one constant per block name
  src/raspberry/block/BlockProperties.php   one constant per block property name
  src/raspberry/item/ItemIds.php            one constant per item name (blocks that can be items included)
  resources/vanilla/item_names.json         the English name of every item, from resource_pack/texts/en_US.lang
  resources/vanilla/creative_items.json     the creative inventory: every survival item, sorted into its tab
  resources/vanilla/block_states.json       every block, its properties and allowed values
  resources/vanilla/recipes.json            every recipe from behavior_pack/recipes, old item names upgraded
  resources/vanilla/block_breaking.json     hardness, tool and drops of every block
  resources/vanilla/item_blocks.json        the block an item places, when it is not the block of the same name
  resources/vanilla/furnace_data.json       how long fuels burn and the experience smelting gives (hand-written tables)

  src/raspberry/network/mcpe/protocol/types/ParticleEffect.php  ---⌍
                                                                   ¦
  one constant per named particle effect (resource_pack/particles)-⌏

  src/raspberry/network/mcpe/protocol/types/LevelEvent.php ----------⌍
  src/raspberry/network/mcpe/protocol/types/ParticleType.php         ¦
                                                                     ¦
  level events and the old numbered particles (hand-written tables) -⌏

  src/raspberry/network/mcpe/protocol/types/LevelSoundEvent.php ---⌍
                                                                   ¦
  one constant per sound event, from Mojang bedrock-protocol-docs -⌏

Usage:
  python tools/generate-block-data.py --version 1.26.60.5

  More advanced:
  python tools/generate-block-data.py --input path/to/folder --version 1.26.60.5

Info:
  the folder holds the mojang-*.json files, en_US.lang, LevelSoundEvent.json and the "recipes", "blocks" and "particles" folders)
"""

import argparse
import json
import re
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

SOURCE_URL = "https://raw.githubusercontent.com/Mojang/bedrock-samples/v{version}/metadata/vanilladata_modules/{file}"
LANG_URL = "https://raw.githubusercontent.com/Mojang/bedrock-samples/v{version}/resource_pack/texts/en_US.lang"

ROOT = Path(__file__).resolve().parent.parent

BLOCK_IDS_PHP = ROOT / "src" / "raspberry" / "block" / "BlockIds.php"
BLOCK_PROPERTIES_PHP = ROOT / "src" / "raspberry" / "block" / "BlockProperties.php"
BLOCK_STATES_JSON = ROOT / "resources" / "vanilla" / "block_states.json"
BIOME_IDS_PHP = ROOT / "src" / "raspberry" / "world" / "biome" / "BiomeIds.php"
ITEM_REGISTRY_JSON = ROOT / "resources" / "vanilla" / "item_registry.json"
ITEM_IDS_PHP = ROOT / "src" / "raspberry" / "item" / "ItemIds.php"
ITEM_NAMES_JSON = ROOT / "resources" / "vanilla" / "item_names.json"
SOUND_EVENTS_PHP = ROOT / "src" / "raspberry" / "network" / "mcpe" / "protocol" / "types" / "LevelSoundEvent.php"

SOUND_EVENTS_URL = "https://raw.githubusercontent.com/Mojang/bedrock-protocol-docs/v{version}/json/LevelSoundEvent.json"

ITEM_NAME_OVERRIDES = {
    "minecraft:undyed_shulker_box": "Shulker Box",
    "minecraft:normal_stone_slab": "Stone Slab",
    "minecraft:disc_fragment_5": "Disc Fragment",
    "minecraft:stone_brick_slab": "Stone Brick Slab",
}

LANG_GROUP_WORDS = {"cobblestone", "stone", "wooden", "flower", "tallgrass"}

LANG_WORD_ALIASES = {"silver": "light_gray", "big": "dark"}

SMALL_WORDS = {"of", "the", "and", "with", "on", "a", "in", "to"}

CREATIVE_ITEMS_JSON = ROOT / "resources" / "vanilla" / "creative_items.json"

CREATIVE_EXCLUDED = re.compile(r"^(air|allow|deny|border_block|barrier|jigsaw|structure_block|structure_void|command_block|chain_command_block|repeating_command_block|command_block_minecart|light_block_\d+|frosted_ice|stonecutter_block|ender_dragon_spawn_egg|wither_spawn_egg|npc_spawn_egg|agent_spawn_egg)$")

CREATIVE_EQUIPMENT = re.compile(r"_(sword|shovel|pickaxe|axe|hoe|spear|helmet|chestplate|leggings|boots|horse_armor|nautilus_armor|harness)$|^(bow|crossbow|trident|mace|shield|elytra|arrow|wolf_armor|totem_of_undying|fishing_rod|shears|flint_and_steel|carrot_on_a_stick|warped_fungus_on_a_stick|brush|saddle|lead|spyglass|compass|recovery_compass|clock|firework_rocket|wind_charge)$")

CREATIVE_NATURE = re.compile(r"_(log|wood|hyphae|stem|leaves|sapling|propagule|ore|tulip|orchid|coral|coral_block|coral_fan|mushroom|mushroom_block|fungus|roots|nylium|petals|dripleaf|bush|vines|egg)$|^(dirt|coarse_dirt|rooted_dirt|dirt_with_roots|grass_block|podzol|mycelium|mud|clay|gravel|sand|red_sand|snow|snow_layer|ice|packed_ice|blue_ice|netherrack|soul_sand|soul_soil|end_stone|obsidian|crying_obsidian|bedrock|stone|deepslate|tuff|calcite|granite|diorite|andesite|basalt|blackstone|dripstone_block|pointed_dripstone|moss_block|moss_carpet|pale_moss_block|pale_moss_carpet|allium|azure_bluet|cornflower|dandelion|poppy|lily_of_the_valley|oxeye_daisy|wither_rose|peony|lilac|rose_bush|sunflower|fern|large_fern|short_grass|tall_grass|deadbush|seagrass|kelp|vine|cactus|bamboo|sugar_cane|pumpkin|carved_pumpkin|melon_block|hay_block|nether_wart_block|warped_wart_block|shroomlight|bee_nest|sculk|sculk_vein|sculk_catalyst|sculk_shrieker|sculk_sensor|amethyst_block|budding_amethyst|amethyst_cluster|azalea|flowering_azalea|spore_blossom|glow_lichen|sponge|wet_sponge|magma|glowstone|waterlily|frog_spawn|sniffer_egg|torchflower|pitcher_plant|chorus_plant|chorus_flower|crimson_roots|warped_roots|twisting_vines|weeping_vines|mangrove_roots|muddy_mangrove_roots|big_dripleaf|small_dripleaf_block|hanging_roots|cave_vines|honeycomb_block|snow_block|powder_snow|eyeblossom|open_eyeblossom|closed_eyeblossom|firefly_bush|leaf_litter|cactus_flower|resin_clump)$")

CREATIVE_VARIANTS = {
    "minecraft:bed": range(16),
    "minecraft:banner": range(16),
    "minecraft:potion": range(47),
    "minecraft:splash_potion": range(47),
    "minecraft:lingering_potion": range(47),
}

VALUE_OVERRIDES = {
    "minecraft:activator_rail": {"rail_direction": [0, 1, 2, 3, 4, 5]},
    "minecraft:detector_rail": {"rail_direction": [0, 1, 2, 3, 4, 5]},
    "minecraft:golden_rail": {"rail_direction": [0, 1, 2, 3, 4, 5]},
    "minecraft:chorus_flower": {"age": [0, 1, 2, 3, 4, 5]},
    "minecraft:cocoa": {"age": [0, 1, 2]},
    "minecraft:frosted_ice": {"age": [0, 1, 2, 3]},
    "minecraft:nether_wart": {"age": [0, 1, 2, 3]},
}

ITEM_ID_OVERRIDES = {
    "minecraft:air": -158,
}

PYTHON_TYPES = {"bool": bool, "int": int, "string": str}

CONST_NAME = re.compile(r"^[A-Z][A-Z0-9_]*$")


def fail(message):
    sys.exit("error: " + message)


def load_source(args, file):
    if args.input is not None:
        with open(Path(args.input) / file, "r", encoding="utf-8") as handle:
            return json.load(handle)

    url = SOURCE_URL.format(version=args.version, file=file)
    print(f"Downloading {url}")

    with urllib.request.urlopen(url, timeout=60) as response:
        return json.loads(response.read().decode("utf-8"))


def read_properties(source):
    properties = {}

    for entry in source["block_properties"]:
        name = entry["name"]
        expected = PYTHON_TYPES.get(entry["type"])

        if expected is None:
            fail(f"property {name} has unknown type {entry['type']}")

        values = [value["value"] for value in entry["values"]]

        for value in values:
            if type(value) is not expected:
                fail(f"property {name} declares {entry['type']} but has value {value!r}")

        if len(values) == 0 or len(values) != len(set(values)):
            fail(f"property {name} has no values or duplicate values")

        if name in properties:
            fail(f"property {name} is declared twice")

        properties[name] = values

    return properties


def read_blocks(source, properties):
    blocks = {}

    for item in source["data_items"]:
        name = item["name"]

        if name in blocks:
            fail(f"block {name} is declared twice")

        overrides = VALUE_OVERRIDES.get(name, {})
        states = {}

        for entry in item["properties"]:
            property_name = entry["name"]

            if property_name not in properties:
                fail(f"block {name} uses undeclared property {property_name}")

            values = properties[property_name]

            if property_name in overrides:
                narrowed = overrides[property_name]

                if not set(narrowed) <= set(values):
                    fail(f"override for {name}.{property_name} contains values Mojang does not list")

                values = narrowed

            states[property_name] = values

        blocks[name] = dict(sorted(states.items()))

    for name, overrides in VALUE_OVERRIDES.items():
        for property_name in overrides:
            if property_name not in blocks.get(name, {}):
                fail(f"override {name}.{property_name} no longer matches Mojang's data, please review it")

    return dict(sorted(blocks.items()))


def read_biomes(source):
    biomes = {}
    seen_ids = {}

    for item in source["data_items"]:
        name = item["name"]
        biome_id = item["id"]

        if type(biome_id) is not int or not 0 <= biome_id <= 0xffff:
            fail(f"biome {name} has invalid ID {biome_id!r}")

        if name in biomes:
            fail(f"biome {name} is declared twice")

        if biome_id in seen_ids:
            fail(f"biomes {name} and {seen_ids[biome_id]} share ID {biome_id}")

        biomes[name] = biome_id
        seen_ids[biome_id] = name

    return biomes


def read_items(item_source, block_source):
    items = {}
    used_ids = {}

    for item in item_source["data_items"]:
        name = item["name"]
        runtime_id = item["raw_id"]

        if type(runtime_id) is not int or not -32768 <= runtime_id <= 32767:
            fail(f"item {name} has invalid ID {runtime_id!r}")

        if name in items:
            fail(f"item {name} is declared twice")

        if runtime_id in used_ids:
            fail(f"items {name} and {used_ids[runtime_id]} share ID {runtime_id}")

        items[name] = runtime_id
        used_ids[runtime_id] = name

    for block in block_source["data_items"]:
        name = block["name"]

        if name in items:
            continue

        block_id = block["raw_id"]
        runtime_id = ITEM_ID_OVERRIDES.get(name, block_id if block_id < 256 else 255 - block_id)

        if runtime_id in used_ids:
            fail(f"block item {name} would reuse ID {runtime_id} of {used_ids[runtime_id]}")

        items[name] = runtime_id
        used_ids[runtime_id] = name

    return dict(sorted(items.items(), key=lambda entry: entry[1]))


def load_lang(args):
    if args.input is not None:
        with open(Path(args.input) / "en_US.lang", "r", encoding="utf-8-sig") as handle:
            text = handle.read()
    else:
        url = LANG_URL.format(version=args.version)
        print(f"Downloading {url}")

        with urllib.request.urlopen(url, timeout=60) as response:
            text = response.read().decode("utf-8-sig")

    lang = {}

    for line in text.splitlines():
        if line.startswith("#") or "=" not in line:
            continue

        key, _, value = line.partition("=")
        lang[key.strip()] = value.split("\t#")[0].strip()

    return lang


def lang_words(text):
    text = re.sub(r"([a-z])([A-Z])", r"\1_\2", text).lower()
    words = []

    for word in re.split(r"[._]", text):
        if word in ("", "tile", "item", "name"):
            continue

        words.extend(LANG_WORD_ALIASES.get(word, word).split("_"))

    return frozenset(words)


def title_case(path):
    words = path.split("_")

    return " ".join(word if index > 0 and word in SMALL_WORDS else word.capitalize() for index, word in enumerate(words))


def read_item_names(item_source, block_source, items, lang):
    keys = {}

    for entry in block_source["data_items"] + item_source["data_items"]:
        serialization_id = entry.get("serialization_id", "")

        if serialization_id != "":
            keys[entry["name"]] = serialization_id if serialization_id.endswith(".name") else serialization_id + ".name"

    by_words = {}

    for key in sorted(lang):
        if (key.startswith("tile.") or key.startswith("item.")) and key.endswith(".name"):
            by_words.setdefault(lang_words(key), key)

    names = {}
    guessed = 0

    for name in items:
        path = name.partition(":")[2]
        words = lang_words(path)
        name_text = ITEM_NAME_OVERRIDES.get(name)

        if name_text is None and path.endswith("_dye"):
            name_text = title_case(path)

        for key in (keys.get(name), f"item.{path}.name", f"tile.{path}.name", by_words.get(words)):
            if name_text is None and key is not None and key in lang:
                name_text = lang[key]

        if name_text is None:
            name_text = grouped_name(path, words, lang)

        if name_text is None:
            name_text = rule_name(path)

        if name_text is None:
            name_text = title_case(path)
            guessed += 1

        names[name] = name_text

    return names, guessed


def grouped_name(path, words, lang):
    for key, text in lang.items():
        parts = key.split(".")

        if len(parts) != 4 or parts[0] != "tile" or parts[3] != "name":
            continue

        group = lang_words(parts[1])
        variant = lang_words(parts[2])

        if variant <= words and words - variant <= group and group - words <= LANG_GROUP_WORDS:
            return text

    return None


def rule_name(path):
    match = re.fullmatch(r"(cod|salmon|pufferfish|tropical_fish|axolotl|tadpole)_bucket", path)

    if match is not None:
        return "Bucket of " + title_case(match.group(1))

    match = re.fullmatch(r"(\w+)_chest_(boat|raft)", path)

    if match is not None:
        return title_case(match.group(1) + "_" + match.group(2)) + " with Chest"

    if path.startswith("music_disc_"):
        return "Music Disc"

    if path.startswith("hard_"):
        return "Hardened " + title_case(path[5:])

    return None


def names_json(version, names):
    entries = [f"    {json.dumps(name)}: {json.dumps(text, ensure_ascii=False)}" for name, text in sorted(names.items())]

    return "{\n" + f'  "version": {json.dumps(version)},\n  "names": {{\n' + ",\n".join(entries) + "\n  }\n}\n"


def read_creative_items(item_source, block_source):
    blocks = {entry["name"] for entry in block_source["data_items"]}
    entries = []

    for entry in item_source["data_items"]:
        name = entry["name"]
        path = name.partition(":")[2]

        if CREATIVE_EXCLUDED.match(path) is not None:
            continue

        if CREATIVE_EQUIPMENT.search(path) is not None:
            category = "equipment"
        elif name in blocks:
            category = "nature" if CREATIVE_NATURE.search(path) is not None else "construction"
        else:
            category = "items"

        family = path.rsplit("_", 1)[-1]

        for meta in CREATIVE_VARIANTS.get(name, [0]):
            entries.append((category, family, path, meta, name))

    order = ["construction", "nature", "equipment", "items"]
    entries.sort(key=lambda entry: (order.index(entry[0]), entry[1], entry[2], entry[3]))

    return [(category, name, meta) for category, _, _, meta, name in entries]


def creative_json(version, entries):
    lines = [f"    [{json.dumps(category)}, {json.dumps(name)}, {meta}]" for category, name, meta in entries]

    return "{\n" + f'  "version": {json.dumps(version)},\n  "items": [\n' + ",\n".join(lines) + "\n  ]\n}\n"


def items_json(version, items):
    entries = [f"    {json.dumps(name)}: {runtime_id}" for name, runtime_id in items.items()]

    return "{\n" + f'  "version": {json.dumps(version)},\n  "items": {{\n' + ",\n".join(entries) + "\n  }\n}\n"

def block_const_name(name):
    namespace, _, path = name.partition(":")

    if namespace != "minecraft":
        fail(f"unexpected namespace in {name}")

    return path.upper()


def property_const_name(name):
    return name.replace(":", "_").upper()


def build_constants(names, to_const):
    constants = {}

    for name in names:
        const = to_const(name)

        if CONST_NAME.match(const) is None:
            fail(f"cannot turn {name} into a PHP constant name")

        if const in constants:
            fail(f"{name} and {constants[const]} both map to constant {const}")

        constants[const] = name

    return dict(sorted(constants.items()))


def php_class(namespace, class_name, description, version, constants, source="bedrock-samples"):
    generated = f"from Mojang {source} v{version}" if source is not None else f"from its hand-written tables (game version {version})"
    lines = [
        "<?php",
        "",
        "declare(strict_types=1);",
        "",
        f"namespace {namespace};",
        "",
        "/**",
        f" * {description}",
        " *",
        f" * Generated by tools/generate-block-data.py {generated}.",
        " * Do not edit this file by hand, run the script again instead!",
        " */",
        f"class {class_name}{{",
        "",
        "    private function __construct(){",
        "    }",
        "",
    ]

    for const, value in constants.items():
        lines.append(f"    public const {const} = {json.dumps(value)};")

    lines.append("}")

    return "\n".join(lines) + "\n"


def states_json(version, blocks):
    lines = [
        "{",
        f'  "version": {json.dumps(version)},',
        '  "blocks": {',
    ]

    entries = [f"    {json.dumps(name)}: {json.dumps(states, separators=(', ', ': '))}" for name, states in blocks.items()]

    lines.append(",\n".join(entries))
    lines.append("  }")
    lines.append("}")

    return "\n".join(lines) + "\n"


def write(path, contents):
    path.parent.mkdir(parents=True, exist_ok=True)

    with open(path, "w", encoding="utf-8", newline="\n") as handle:
        handle.write(contents)

    print(f"Wrote {path.relative_to(ROOT)}")


RECIPES_JSON = ROOT / "resources" / "vanilla" / "recipes.json"
RECIPES_TREE_URL = "https://api.github.com/repos/Mojang/bedrock-samples/git/trees/v{version}?recursive=1"
RECIPES_FILE_URL = "https://raw.githubusercontent.com/Mojang/bedrock-samples/v{version}/{path}"

PACK_TREE = None

RECIPE_ITEM_ALIASES = {
    ("minecraft:appleenchanted", None): ("minecraft:enchanted_golden_apple", 0),
    ("minecraft:carrotonastick", None): ("minecraft:carrot_on_a_stick", 0),
    ("minecraft:chain", None): ("minecraft:iron_chain", 0),
    ("minecraft:chorus_fruit_popped", None): ("minecraft:popped_chorus_fruit", 0),
    ("minecraft:fish", None): ("minecraft:cod", 0),
    ("minecraft:cooked_fish", None): ("minecraft:cooked_cod", 0),
    ("minecraft:sign", None): ("minecraft:oak_sign", 0),
    ("minecraft:darkoak_sign", None): ("minecraft:dark_oak_sign", 0),
    ("minecraft:horsearmorleather", None): ("minecraft:leather_horse_armor", 0),
    ("minecraft:horsearmoriron", None): ("minecraft:iron_horse_armor", 0),
    ("minecraft:horsearmorgold", None): ("minecraft:golden_horse_armor", 0),
    ("minecraft:melon", None): ("minecraft:melon_slice", 0),
    ("minecraft:speckled_melon", None): ("minecraft:glistering_melon_slice", 0),
    ("minecraft:muttonraw", None): ("minecraft:mutton", 0),
    ("minecraft:muttoncooked", None): ("minecraft:cooked_mutton", 0),
    ("minecraft:netherstar", None): ("minecraft:nether_star", 0),
    ("minecraft:record_5", None): ("minecraft:music_disc_5", 0),
    ("minecraft:sealantern", None): ("minecraft:sea_lantern", 0),
    ("minecraft:turtle_shell_piece", None): ("minecraft:turtle_scute", 0),
    ("minecraft:coal", 1): ("minecraft:charcoal", 0),
    ("minecraft:bucket", 1): ("minecraft:milk_bucket", 0),
    ("minecraft:quartz_block", 2): ("minecraft:quartz_pillar", 0),
    ("minecraft:purpur_block", 2): ("minecraft:purpur_pillar", 0),
    ("minecraft:sandstone", 3): ("minecraft:smooth_sandstone", 0),
    ("minecraft:skull", 1): ("minecraft:wither_skeleton_skull", 0),
    ("minecraft:skull", 4): ("minecraft:creeper_head", 0),
    **{("minecraft:emptymap", data): ("minecraft:empty_map", data) for data in (None, 0, 1, 2)},
    **{("minecraft:dye", data): ("minecraft:" + name, 0) for data, name in enumerate([
        "ink_sac", "red_dye", "green_dye", "cocoa_beans", "lapis_lazuli", "purple_dye", "cyan_dye", "light_gray_dye", "gray_dye", "pink_dye",
        "lime_dye", "yellow_dye", "light_blue_dye", "magenta_dye", "orange_dye", "bone_meal", "black_dye", "brown_dye", "blue_dye", "white_dye"])},
    **{("minecraft:carpet", data): ("minecraft:" + color + "_carpet", 0) for data, color in enumerate([
        "white", "orange", "magenta", "light_blue", "yellow", "lime", "pink", "gray", "light_gray", "cyan", "purple", "blue", "brown", "green", "red", "black"])},
    **{("minecraft:boat", data): ("minecraft:" + wood, 0) for data, wood in enumerate([
        "oak_boat", "spruce_boat", "birch_boat", "jungle_boat", "acacia_boat", "dark_oak_boat", "mangrove_boat", "bamboo_raft"])},
    **{("minecraft:chest_boat", data): ("minecraft:" + wood, 0) for data, wood in enumerate([
        "oak_chest_boat", "spruce_chest_boat", "birch_chest_boat", "jungle_chest_boat", "acacia_chest_boat", "dark_oak_chest_boat", "mangrove_chest_boat", "bamboo_chest_raft"])},
    **{("minecraft:banner_pattern", data): ("minecraft:" + name + "_banner_pattern", 0) for data, name in enumerate([
        "creeper", "skull", "flower", "mojang", "field_masoned", "bordure_indented"])},
}

RECIPE_OBSOLETE_ITEMS = {"minecraft:log", "minecraft:log2", "minecraft:wood"}

RECIPE_META_ITEMS = {"minecraft:banner", "minecraft:suspicious_stew", "minecraft:empty_map", "minecraft:potion", "minecraft:splash_potion", "minecraft:lingering_potion"}

POTION_TYPES = [
    "water", "mundane", "long_mundane", "thick", "awkward", "nightvision", "long_nightvision", "invisibility", "long_invisibility", "leaping",
    "long_leaping", "strong_leaping", "fire_resistance", "long_fire_resistance", "swiftness", "long_swiftness", "strong_swiftness", "slowness",
    "long_slowness", "water_breathing", "long_water_breathing", "healing", "strong_healing", "harming", "strong_harming", "poison", "long_poison",
    "strong_poison", "regeneration", "long_regeneration", "strong_regeneration", "strength", "long_strength", "strong_strength", "weakness",
    "long_weakness", "wither", "turtle_master", "long_turtle_master", "strong_turtle_master", "slow_falling", "long_slow_falling", "strong_slowness",
    "wind_charged", "weaving", "oozing", "infested",
]

ITEM_TAGS = {
    "minecraft:planks": r"^\w+_planks$",
    "minecraft:logs": r"^(stripped_)?\w+_(log|wood)$|^(stripped_)?(crimson|warped)_(stem|hyphae)$",
    "minecraft:logs_that_burn": r"^(stripped_)?\w+_(log|wood)$",
    "minecraft:wooden_slabs": r"^(oak|spruce|birch|jungle|acacia|dark_oak|mangrove|cherry|bamboo|crimson|warped|pale_oak|poplar)_slab$",
    "minecraft:wool": r"^\w+_wool$",
    "minecraft:is_pickaxe": r"^\w+_pickaxe$",
    "minecraft:trim_templates": r"^\w+_armor_trim_smithing_template$",
    "minecraft:trimmable_armors": r"^\w+_(helmet|chestplate|leggings|boots)$",
    "minecraft:coals": ["coal", "charcoal"],
    "minecraft:stone_tool_materials": ["cobblestone", "cobbled_deepslate", "blackstone"],
    "minecraft:stone_crafting_materials": ["cobblestone", "cobbled_deepslate", "blackstone"],
    "minecraft:metal_nuggets": ["iron_nugget", "gold_nugget", "copper_nugget"],
    "minecraft:egg": ["egg", "blue_egg", "brown_egg"],
    "minecraft:soul_fire_base_blocks": ["soul_sand", "soul_soil"],
    "minecraft:mushrooms_for_stew": ["brown_mushroom", "red_mushroom"],
    "minecraft:trim_materials": ["iron_ingot", "copper_ingot", "gold_ingot", "lapis_lazuli", "emerald", "diamond", "netherite_ingot", "redstone", "quartz", "amethyst_shard", "resin_brick"],
}

RECIPE_TYPES = {
    "minecraft:recipe_shaped": "shaped",
    "minecraft:recipe_shapeless": "shapeless",
    "minecraft:recipe_furnace": "furnace",
    "minecraft:recipe_brewing_mix": "brewing_mix",
    "minecraft:recipe_brewing_container": "brewing_container",
    "minecraft:recipe_smithing_transform": "smithing_transform",
    "minecraft:recipe_smithing_trim": "smithing_trim",
}

UNLOCK_CONTEXTS = {"None", "AlwaysUnlocked", "PlayerInWater", "PlayerHasManyItems"}


class SkipRecipe(Exception):
    pass


def download_text(url, attempts=6):
    for attempt in range(1, attempts + 1):
        try:
            with urllib.request.urlopen(url, timeout=60) as response:
                return response.read().decode("utf-8-sig")
        except (urllib.error.URLError, ConnectionError, TimeoutError) as error:
            if attempt == attempts:
                fail(f"cannot download {url}: {error}")

            time.sleep(attempt * 2)


def load_pack_files(args, folder, pack="behavior_pack"):
    files = []

    if args.input is not None:
        for path in sorted((Path(args.input) / folder).glob("*.json")):
            with open(path, "r", encoding="utf-8-sig") as handle:
                files.append((path.name, handle.read()))

        return files

    from concurrent.futures import ThreadPoolExecutor

    global PACK_TREE

    if PACK_TREE is None:
        url = RECIPES_TREE_URL.format(version=args.version)
        print(f"Downloading {url}")
        PACK_TREE = json.loads(download_text(url))["tree"]

    prefix = f"{pack}/{folder}/"
    paths = sorted(entry["path"] for entry in PACK_TREE if entry["path"].startswith(prefix) and entry["path"].endswith(".json") and "/" not in entry["path"][len(prefix):])

    def download(path):
        return path.rsplit("/", 1)[-1], download_text(RECIPES_FILE_URL.format(version=args.version, path=path))

    print(f"Downloading {len(paths)} files from {prefix}")

    with ThreadPoolExecutor(4) as pool:
        return list(pool.map(download, paths))


def load_recipe_files(args):
    return load_pack_files(args, "recipes")


def build_item_tags(items):
    tags = {}

    for tag, members in ITEM_TAGS.items():
        if isinstance(members, str):
            names = [name for name in items if re.search(members, name.partition(":")[2]) is not None]
        else:
            names = ["minecraft:" + name for name in members]

            for name in names:
                if name not in items:
                    fail(f"item tag {tag} lists unknown item {name}")

        if not names:
            fail(f"item tag {tag} has no items")

        tags[tag] = sorted(names)

    return tags


def recipe_item(name, data, items):
    if not name.startswith("minecraft:"):
        name = "minecraft:" + name

    parts = name.split(":")

    if len(parts) == 3:
        name, data = parts[0] + ":" + parts[1], int(parts[2])

    if name in RECIPE_OBSOLETE_ITEMS:
        raise SkipRecipe(f"uses {name}")

    alias = RECIPE_ITEM_ALIASES.get((name.lower(), data)) or RECIPE_ITEM_ALIASES.get((name.lower(), None))

    if alias is not None:
        name, data = alias[0], alias[1] if data is not None else None

        if name not in RECIPE_META_ITEMS and data is not None:
            data = 0

    if name not in items:
        fail(f"recipe item {name} is unknown, add it to RECIPE_ITEM_ALIASES")

    if name not in RECIPE_META_ITEMS and data is not None:
        data = 0

    return name, data


def recipe_ingredient(entry, items, tags):
    if isinstance(entry, str):
        entry = {"item": entry}

    count = entry.get("count", 1)

    if "tag" in entry:
        if entry["tag"] not in tags:
            fail(f"unknown item tag {entry['tag']}, add it to ITEM_TAGS")

        return {"tag": entry["tag"], "count": count}

    name, data = recipe_item(entry["item"], entry.get("data"), items)

    return {"item": name, "meta": -1 if data is None else data, "count": count}


def recipe_result(entry, items):
    if isinstance(entry, str):
        entry = {"item": entry}

    name, data = recipe_item(entry["item"], entry.get("data", 0), items)

    return {"item": name, "meta": data or 0, "count": entry.get("count", 1)}


def recipe_unlock(value, items, tags):
    if value is None:
        return None

    if isinstance(value, dict):
        value = [value]

    if len(value) == 1 and "context" in value[0]:
        context = value[0]["context"]

        if context not in UNLOCK_CONTEXTS:
            fail(f"unknown unlock context {context}")

        return {"context": context, "items": []}

    return {"context": "None", "items": [recipe_ingredient(entry, items, tags) for entry in value]}


def potion_meta(name):
    prefix = "minecraft:potion_type:"

    if not name.startswith(prefix) or name[len(prefix):].lower() not in POTION_TYPES:
        fail(f"unknown potion {name}, add it to POTION_TYPES")

    return POTION_TYPES.index(name[len(prefix):].lower())


def read_recipe(kind, body, items, tags):
    tags_of_recipe = body.get("tags", [])
    common = {"id": body["description"]["identifier"]}

    if kind in ("shaped", "shapeless"):
        if len(tags_of_recipe) != 1:
            fail(f"recipe {common['id']} has {len(tags_of_recipe)} tags")

        common["block"] = tags_of_recipe[0]
        common["priority"] = body.get("priority", 0)
        results = body["result"] if isinstance(body["result"], list) else [body["result"]]
        common["output"] = [recipe_result(entry, items) for entry in results]
        common["unlock"] = recipe_unlock(body.get("unlock"), items, tags)

    if kind == "shaped":
        pattern = body["pattern"]
        width = max(len(row) for row in pattern)
        keys = {symbol: recipe_ingredient(entry, items, tags) for symbol, entry in body["key"].items()}
        cells = []

        for row in pattern:
            for symbol in row.ljust(width):
                if symbol == " ":
                    cells.append(None)
                elif symbol in keys:
                    cells.append(keys[symbol])
                else:
                    fail(f"recipe {common['id']} uses undefined key {symbol}")

        return {**common, "width": width, "height": len(pattern), "input": cells}

    if kind == "shapeless":
        inputs = []

        for entry in body["ingredients"]:
            ingredient = recipe_ingredient(entry, items, tags)
            inputs += [{**ingredient, "count": 1}] * ingredient["count"]

        return {**common, "input": inputs}

    if kind == "furnace":
        return {**common, "blocks": tags_of_recipe, "priority": body.get("priority", 0),
                "input": recipe_ingredient(body["input"], items, tags), "output": recipe_result(body["output"], items)}

    if kind == "brewing_mix":
        return {**common, "input": potion_meta(body["input"]), "reagent": recipe_ingredient(body["reagent"], items, tags), "output": potion_meta(body["output"])}

    if kind == "brewing_container":
        return {**common, "input": recipe_item(body["input"], 0, items)[0], "reagent": recipe_ingredient(body["reagent"], items, tags),
                "output": recipe_item(body["output"], 0, items)[0]}

    smithing = {part: recipe_ingredient(body[part], items, tags) for part in ("template", "base", "addition")}

    if kind == "smithing_transform":
        return {**common, **smithing, "output": recipe_result(body["result"], items)}

    return {**common, **smithing}


def read_recipes(files, items, potion_source):
    tags = build_item_tags(items)

    for name in POTION_TYPES:
        if "minecraft:" + name not in {entry["name"] for entry in potion_source["data_items"]}:
            fail(f"potion {name} is not in mojang-potion-effects.json")

    recipes = {kind: [] for kind in RECIPE_TYPES.values()}
    seen = set()
    skipped = 0

    for file_name, text in files:
        data = json.loads(text)

        for key, body in data.items():
            if key == "format_version":
                continue

            kind = RECIPE_TYPES.get(key)

            if kind is None:
                fail(f"{file_name}: unknown recipe type {key}")

            if "deprecated" in body.get("tags", []):
                continue

            try:
                recipe = read_recipe(kind, body, items, tags)
            except SkipRecipe:
                skipped += 1
                continue

            if recipe["id"] in seen:
                recipe["id"] = "minecraft:" + file_name.removesuffix(".json")

            if recipe["id"] in seen:
                fail(f"recipe ID {recipe['id']} is used twice")

            seen.add(recipe["id"])
            recipes[kind].append(recipe)

    for entries in recipes.values():
        entries.sort(key=lambda recipe: recipe["id"])

    return tags, recipes, skipped


def recipes_json(version, tags, recipes):
    lines = ["{", f'  "version": {json.dumps(version)},', f'  "potion_types": {json.dumps(POTION_TYPES)},', '  "item_tags": {']
    lines.append(",\n".join(f"    {json.dumps(tag)}: {json.dumps(names)}" for tag, names in tags.items()))
    lines.append("  },")

    groups = []

    for kind, entries in recipes.items():
        body = ",\n".join("    " + json.dumps(recipe, separators=(", ", ": ")) for recipe in entries)
        groups.append(f'  "{kind}": [\n{body}\n  ]')

    lines.append(",\n".join(groups))
    lines.append("}")

    return "\n".join(lines) + "\n"


BLOCK_BREAKING_JSON = ROOT / "resources" / "vanilla" / "block_breaking.json"


def drop(item, low=1, high=None, chance=1.0, states=None):
    entry = {"item": "minecraft:" + item, "min": low, "max": low if high is None else high}

    if chance < 1.0:
        entry["chance"] = chance

    if states is not None:
        entry["states"] = states

    return entry


NOTHING = []

BLOCK_RULES = [
    # never breakable in survival
    (r"^(air|bedrock|barrier|border_block|allow|deny|command_block|chain_command_block|repeating_command_block|structure_block|structure_void|jigsaw|end_portal|end_portal_frame|portal|bubble_column|light_block_\d+|water|flowing_water|lava|flowing_lava|piston_arm_collision|sticky_piston_arm_collision|moving_block|camera|unknown|reinforced_deepslate)$", -1, None, None, NOTHING),
    (r"^(fire|soul_fire)$", 0, None, None, NOTHING),

    # ores
    (r"^coal_ore$", 3, "pickaxe", "wooden", [drop("coal")]),
    (r"^deepslate_coal_ore$", 4.5, "pickaxe", "wooden", [drop("coal")]),
    (r"^(copper_ore)$", 3, "pickaxe", "stone", [drop("raw_copper", 2, 5)]),
    (r"^deepslate_copper_ore$", 4.5, "pickaxe", "stone", [drop("raw_copper", 2, 5)]),
    (r"^iron_ore$", 3, "pickaxe", "stone", [drop("raw_iron")]),
    (r"^deepslate_iron_ore$", 4.5, "pickaxe", "stone", [drop("raw_iron")]),
    (r"^lapis_ore$", 3, "pickaxe", "stone", [drop("lapis_lazuli", 4, 9)]),
    (r"^deepslate_lapis_ore$", 4.5, "pickaxe", "stone", [drop("lapis_lazuli", 4, 9)]),
    (r"^gold_ore$", 3, "pickaxe", "iron", [drop("raw_gold")]),
    (r"^deepslate_gold_ore$", 4.5, "pickaxe", "iron", [drop("raw_gold")]),
    (r"^(lit_)?redstone_ore$", 3, "pickaxe", "iron", [drop("redstone", 4, 5)]),
    (r"^(lit_)?deepslate_redstone_ore$", 4.5, "pickaxe", "iron", [drop("redstone", 4, 5)]),
    (r"^diamond_ore$", 3, "pickaxe", "iron", [drop("diamond")]),
    (r"^deepslate_diamond_ore$", 4.5, "pickaxe", "iron", [drop("diamond")]),
    (r"^emerald_ore$", 3, "pickaxe", "iron", [drop("emerald")]),
    (r"^deepslate_emerald_ore$", 4.5, "pickaxe", "iron", [drop("emerald")]),
    (r"^nether_gold_ore$", 3, "pickaxe", "wooden", [drop("gold_nugget", 2, 6)]),
    (r"^quartz_ore$", 3, "pickaxe", "wooden", [drop("quartz")]),
    (r"^ancient_debris$", 30, "pickaxe", "diamond", None),
    (r"^gilded_blackstone$", 1.5, "pickaxe", "wooden", None),

    # mineral blocks
    (r"^(coal_block|redstone_block)$", 5, "pickaxe", "wooden", None),
    (r"^(iron_block|raw_iron_block|raw_copper_block)$", 5, "pickaxe", "stone", None),
    (r"^lapis_block$", 3, "pickaxe", "stone", None),
    (r"^(gold_block)$", 3, "pickaxe", "iron", None),
    (r"^(raw_gold_block|diamond_block|emerald_block)$", 5, "pickaxe", "iron", None),
    (r"^netherite_block$", 50, "pickaxe", "diamond", None),
    (r"^(obsidian|crying_obsidian)$", 50, "pickaxe", "diamond", None),
    (r"^respawn_anchor$", 50, "pickaxe", "diamond", None),
    (r"^(waxed_)?((exposed|weathered|oxidized)_)?(copper|copper_block|cut_copper|chiseled_copper)$", 3, "pickaxe", "stone", None),
    (r"^(waxed_)?((exposed|weathered|oxidized)_)?copper_(grate|bulb|door|trapdoor|chest|golem_statue)$", 3, "pickaxe", "stone", None),
    (r"^(waxed_)?((exposed|weathered|oxidized)_)?(copper_bars|copper_chain)$", 5, "pickaxe", "wooden", None),
    (r"^(waxed_)?((exposed|weathered|oxidized)_)?(copper_lantern)$", 3.5, "pickaxe", "wooden", None),
    (r"^(waxed_)?((exposed|weathered|oxidized)_)?lightning_rod$", 3, "pickaxe", "stone", None),
    (r"^(waxed_)?((exposed|weathered|oxidized)_)?double_cut_copper_slab$", 3, "pickaxe", "stone", [drop("cut_copper_slab", 2)]),

    # stone and friends
    (r"^stone$", 1.5, "pickaxe", "wooden", [drop("cobblestone")]),
    (r"^deepslate$", 3, "pickaxe", "wooden", [drop("cobbled_deepslate")]),
    (r"^infested_\w+$", 0.75, "pickaxe", None, NOTHING),
    (r"^(granite|diorite|andesite|polished_granite|polished_diorite|polished_andesite|stone_bricks|mossy_stone_bricks|cracked_stone_bricks|chiseled_stone_bricks|tuff|polished_tuff|tuff_bricks|chiseled_tuff|chiseled_tuff_bricks|dripstone_block|pointed_dripstone|prismarine|dark_prismarine|prismarine_bricks|purpur_block|purpur_pillar|blackstone|polished_blackstone|polished_blackstone_bricks|cracked_polished_blackstone_bricks|chiseled_polished_blackstone|mud_bricks|packed_mud|resin_bricks|chiseled_resin_bricks|amethyst_block|budding_amethyst)$", 1.5, "pickaxe", "wooden", None),
    (r"^(cobblestone|mossy_cobblestone|smooth_stone|brick_block|nether_brick|red_nether_brick|cracked_nether_bricks|chiseled_nether_bricks|smooth_sandstone|smooth_red_sandstone|smooth_quartz|grindstone)$", 2, "pickaxe", "wooden", None),
    (r"^(cobbled_deepslate|polished_deepslate|deepslate_bricks|deepslate_tiles|cracked_deepslate_bricks|cracked_deepslate_tiles|chiseled_deepslate)$", 3.5, "pickaxe", "wooden", None),
    (r"^(end_stone|end_bricks)$", 3, "pickaxe", "wooden", None),
    (r"^(sandstone|red_sandstone|cut_sandstone|cut_red_sandstone|chiseled_sandstone|chiseled_red_sandstone|quartz_block|quartz_bricks|quartz_pillar|chiseled_quartz_block)$", 0.8, "pickaxe", "wooden", None),
    (r"^calcite$", 0.75, "pickaxe", "wooden", None),
    (r"^netherrack$", 0.4, "pickaxe", "wooden", None),
    (r"^(basalt|polished_basalt|smooth_basalt)$", 1.25, "pickaxe", "wooden", None),
    (r"^(hardened_clay|\w+_terracotta)$", 1.25, "pickaxe", "wooden", None),
    (r"^\w+_glazed_terracotta$", 1.4, "pickaxe", "wooden", None),
    (r"^\w+_concrete$", 1.8, "pickaxe", "wooden", None),
    (r"^magma$", 0.5, "pickaxe", "wooden", None),
    (r"^bone_block$", 2, "pickaxe", "wooden", None),
    (r"^(\w+_)?(cinnabar|sulfur)(_bricks)?$|^(polished|chiseled)_(cinnabar|sulfur)$|^potent_sulfur$", 1.5, "pickaxe", "wooden", None),
    (r"^sulfur_spike$", 1.5, "pickaxe", "wooden", None),
    (r"^(small|medium|large)_amethyst_bud$", 1.5, "pickaxe", None, NOTHING),
    (r"^amethyst_cluster$", 1.5, "pickaxe", "wooden", [drop("amethyst_shard", 4)]),
    (r"^(ice|packed_ice|frosted_ice|ice_crystal|icicle)$", 0.5, "pickaxe", None, NOTHING),
    (r"^blue_ice$", 2.8, "pickaxe", None, NOTHING),

    # metal and machines
    (r"^(anvil|chipped_anvil|damaged_anvil|enchanting_table)$", 5, "pickaxe", "wooden", None),
    (r"^(iron_bars|iron_door|iron_trapdoor|iron_chain|bell)$", 5, "pickaxe", "wooden", None),
    (r"^lit_(furnace|blast_furnace|smoker)$", 3.5, "pickaxe", "wooden", "unlit"),
    (r"^(furnace|blast_furnace|smoker|dispenser|dropper|lantern|soul_lantern|stonecutter_block|lodestone)$", 3.5, "pickaxe", "wooden", None),
    (r"^(observer|hopper|conduit)$", 3, "pickaxe", "wooden", None),
    (r"^(cauldron)$", 2, "pickaxe", "wooden", None),
    (r"^(brewing_stand)$", 0.5, "pickaxe", "wooden", None),
    (r"^ender_chest$", 22.5, "pickaxe", "wooden", [drop("obsidian", 8)]),
    (r"^mob_spawner$", 5, "pickaxe", "wooden", NOTHING),
    (r"^(trial_spawner|vault)$", 50, "pickaxe", None, NOTHING),
    (r"^heavy_core$", 10, "pickaxe", None, None),
    (r"^crafter$", 1.5, "pickaxe", "wooden", None),
    (r"^(\w+_)?shulker_box$", 2, "pickaxe", None, None),
    (r"^(rail|golden_rail|detector_rail|activator_rail)$", 0.7, "pickaxe", None, None),
    (r"^(stone_button|polished_blackstone_button)$", 0.5, "pickaxe", None, None),
    (r"^(stone_pressure_plate|polished_blackstone_pressure_plate|light_weighted_pressure_plate|heavy_weighted_pressure_plate)$", 0.5, "pickaxe", "wooden", None),
    (r"^(piston|sticky_piston)$", 1.5, "pickaxe", None, None),
    (r"^(daylight_detector|daylight_detector_inverted)$", 0.2, "axe", None, [drop("daylight_detector")]),
    (r"^dragon_egg$", 3, None, None, None),
    (r"^beacon$", 3, None, None, None),

    # wood
    (r"^(stripped_)?\w+_(log|wood|stem|hyphae)$", 2, "axe", None, None),
    (r"^(stripped_)?bamboo_block$", 2, "axe", None, None),
    (r"^\w+_planks$|^bamboo_mosaic$", 2, "axe", None, None),
    (r"^(\w+_)?fence(_gate)?$", 2, "axe", None, None),
    (r"^(\w+_)?door$", 3, "axe", None, None),
    (r"^(\w+_)?trapdoor$", 3, "axe", None, None),
    (r"^(\w+_)?button$", 0.5, "axe", None, None),
    (r"^(\w+_)?pressure_plate$", 0.5, "axe", None, None),
    (r"^(standing_sign|wall_sign|\w+_standing_sign|\w+_wall_sign|\w+_hanging_sign)$", 1, "axe", None, "sign"),
    (r"^(standing_banner|wall_banner)$", 1, "axe", None, [drop("banner")]),
    (r"^\w+_shelf$", 2, "axe", None, None),
    (r"^(chest|trapped_chest|barrel|crafting_table|cartography_table|fletching_table|smithing_table|loom|lectern)$", 2.5, "axe", None, None),
    (r"^bookshelf$", 1.5, "axe", None, [drop("book", 3)]),
    (r"^chiseled_bookshelf$", 1.5, "axe", None, None),
    (r"^(jukebox|campfire|soul_campfire)$", 2, "axe", None, None),
    (r"^noteblock$", 0.8, "axe", None, None),
    (r"^(composter|beehive)$", 0.6, "axe", None, None),
    (r"^bee_nest$", 0.3, "axe", None, NOTHING),
    (r"^ladder$", 0.4, "axe", None, None),
    (r"^(pumpkin|carved_pumpkin|lit_pumpkin|melon_block)$", 1, "axe", None, None),
    (r"^(mangrove_roots)$", 0.7, "axe", None, None),
    (r"^(chorus_plant|chorus_flower)$", 0.4, "axe", None, NOTHING),
    (r"^cocoa$", 0.2, "axe", None, [drop("cocoa_beans"), drop("cocoa_beans", 1, 2, states={"age": 2})]),
    (r"^(brown_mushroom_block|red_mushroom_block|mushroom_stem)$", 0.2, "axe", None, NOTHING),
    (r"^creaking_heart$", 10, "axe", None, None),

    # dirt and sand
    (r"^(grass_block|grass_path|farmland)$", 0.6, "shovel", None, [drop("dirt")]),
    (r"^(mycelium|podzol)$", 0.5, "shovel", None, [drop("dirt")]),
    (r"^(dirt|coarse_dirt|dirt_with_roots|sand|red_sand|soul_sand|soul_soil|mud|\w+_concrete_powder)$", 0.5, "shovel", None, None),
    (r"^(crimson_nylium|warped_nylium)$", 0.4, "pickaxe", "wooden", [drop("netherrack")]),
    (r"^gravel$", 0.6, "shovel", None, None),
    (r"^clay$", 0.6, "shovel", None, [drop("clay_ball", 4)]),
    (r"^muddy_mangrove_roots$", 0.7, "shovel", None, None),
    (r"^(suspicious_sand|suspicious_gravel)$", 0.25, "shovel", None, NOTHING),
    (r"^snow$", 0.2, "shovel", "wooden", [drop("snowball", 4)]),
    (r"^snow_layer$", 0.1, "shovel", "wooden", [drop("snowball")]),
    (r"^powder_snow$", 0.25, None, None, NOTHING),

    # leaves and soft blocks
    (r"^(\w+_)?leaves(_flowered)?$", 0.2, "hoe", None, "leaves"),
    (r"^(hay_block|target|dried_kelp_block)$", 0.5, "hoe", None, None),
    (r"^(sponge|wet_sponge)$", 0.6, "hoe", None, None),
    (r"^(nether_wart_block|warped_wart_block|shroomlight)$", 1, "hoe", None, None),
    (r"^(moss_block|pale_moss_block)$", 0.1, "hoe", None, None),
    (r"^(moss_carpet|pale_moss_carpet)$", 0.1, None, None, None),
    (r"^(sculk|sculk_vein)$", 0.2, "hoe", None, NOTHING),
    (r"^(sculk_sensor|calibrated_sculk_sensor|sculk_catalyst|sculk_shrieker)$", 1.5, "hoe", None, None),
    (r"^\w+_wool$", 0.8, "shears", None, None),
    (r"^\w+_carpet$", 0.1, None, None, None),
    (r"^web$", 4, "sword", None, [drop("string")]),
    (r"^(vine|glow_lichen|weeping_vines|twisting_vines|cave_vines\w*|pale_hanging_moss)$", 0.2, "shears", None, NOTHING),

    # glass and light
    (r"^(hard_)?(\w+_)?(stained_)?glass(_pane)?$", 0.3, None, None, NOTHING),
    (r"^tinted_glass$", 0.3, None, None, None),
    (r"^glowstone$", 0.3, None, None, [drop("glowstone_dust", 2, 4)]),
    (r"^sea_lantern$", 0.3, None, None, [drop("prismarine_crystals", 2, 3)]),
    (r"^(redstone_lamp|lit_redstone_lamp)$", 0.3, None, None, [drop("redstone_lamp")]),
    (r"^\w+_froglight$", 0.3, None, None, None),

    # plants and small things that break at once
    (r"^(short_grass|tall_grass|fern|large_fern|short_dry_grass|tall_dry_grass|seagrass)$", 0, None, None, [drop("wheat_seeds", 1, 1, 0.125)]),
    (r"^deadbush$", 0, None, None, [drop("stick", 0, 2)]),
    (r"^wheat$", 0, None, None, [drop("wheat_seeds"), drop("wheat", 1, 1, states={"growth": 7}), drop("wheat_seeds", 0, 3, states={"growth": 7})]),
    (r"^carrots$", 0, None, None, [drop("carrot"), drop("carrot", 0, 3, states={"growth": 7})]),
    (r"^potatoes$", 0, None, None, [drop("potato"), drop("potato", 0, 3, states={"growth": 7})]),
    (r"^beetroot$", 0, None, None, [drop("beetroot_seeds"), drop("beetroot", 1, 1, states={"growth": 7}), drop("beetroot_seeds", 0, 3, states={"growth": 7})]),
    (r"^nether_wart$", 0, None, None, [drop("nether_wart"), drop("nether_wart", 1, 3, states={"age": 3})]),
    (r"^(melon_stem|pumpkin_stem)$", 0, None, None, NOTHING),
    (r"^sweet_berry_bush$", 0, None, None, [drop("sweet_berries", 1, 2, states={"growth": 7})]),
    (r"^(torchflower_crop|pitcher_crop)$", 0, None, None, NOTHING),
    (r"^reeds$", 0, None, None, [drop("sugar_cane")]),
    (r"^redstone_wire$", 0, None, None, [drop("redstone")]),
    (r"^(unlit_)?redstone_torch$", 0, None, None, [drop("redstone_torch")]),
    (r"^(powered|unpowered)_repeater$", 0, None, None, [drop("repeater")]),
    (r"^(powered|unpowered)_comparator$", 0, None, None, [drop("comparator")]),
    (r"^trip_wire$", 0, None, None, [drop("string")]),
    (r"^(kelp)$", 0, None, None, [drop("kelp")]),
    (r"^(cake|\w*candle_cake)$", 0.5, None, None, NOTHING),
    (r"^flower_pot$", 0, None, None, None),
    (r"^(frame|glow_frame)$", 0.25, None, None, None),
    (r"^(\w+_)?bed$", 0.2, None, None, None),
    (r"^(\w+_)?candle$", 0.1, None, None, None),
    (r"^(skeleton_skull|wither_skeleton_skull|zombie_head|player_head|creeper_head|dragon_head|piglin_head)$", 1, None, None, None),
    (r"^lever$", 0.5, None, None, None),
    (r"^cactus$", 0.4, None, None, None),
    (r"^(bamboo)$", 1, "axe", None, None),
    (r"^(big_dripleaf)$", 0.1, "axe", None, None),
    (r"^(scaffolding|slime|honey_block|tnt|underwater_tnt|frog_spawn|turtle_egg|sniffer_egg|decorated_pot)$", 0, None, None, None),
    (r"^honeycomb_block$", 0.6, None, None, None),
    (r"^(end_rod|torch|soul_torch|copper_torch|underwater_torch|colored_torch_\w+|tripwire_hook|sea_pickle|waterlily|spore_blossom|hanging_roots|small_dripleaf_block|resin_clump|leaf_litter|pink_petals|wildflowers|firefly_bush|bush|cactus_flower|azalea|flowering_azalea|mangrove_propagule|shelf_mushroom|red_shrub|dried_ghast)$", 0, None, None, None),
    (r"^(\w+_)?(sapling|tulip|orchid|mushroom|fungus|roots|sprouts|coral|coral_fan|coral_wall_fan|eyeblossom|dandelion)$", 0, None, None, None),
    (r"^(\w+_)?coral_block$", 1.5, "pickaxe", "wooden", None),
    (r"^(allium|azure_bluet|cornflower|poppy|oxeye_daisy|lily_of_the_valley|wither_rose|torchflower|sunflower|lilac|rose_bush|peony|pitcher_plant)$", 0, None, None, None),
    (r"^(element_\d+|element_constructor|compound_creator|lab_table|material_reducer|chemical_heat)$", 0, None, None, None),
    (r"^resin_block$", 0, None, None, None),
    (r"^end_stone_brick_(double_slab|slab|wall)$", 3, "pickaxe", "wooden", None),
    (r"^normal_stone_(double_slab|slab|stairs)$", 1.5, "pickaxe", "wooden", None),
    (r"^petrified_oak_(double_slab|slab)$", 2, "pickaxe", "wooden", None),
    (r"^disc_press$", 3.5, "pickaxe", "wooden", None),
]

SHAPE_SUFFIXES = ("_double_slab", "_slab", "_stairs", "_wall")


def load_block_files(args):
    return load_pack_files(args, "blocks")


def block_base(path, rules_by_path):
    for suffix in SHAPE_SUFFIXES:
        if path.endswith(suffix):
            base = path[:-len(suffix)]

            for candidate in (base, base + "s", base + "_block", base + "_planks"):
                if candidate in rules_by_path:
                    return candidate

    return None


def match_rule(path):
    for pattern, hardness, tool, tier, drops in BLOCK_RULES:
        if re.search(pattern, path) is not None:
            return hardness, tool, tier, drops

    return None


def read_block_breaking(blocks, items, block_files):
    mojang = {}

    for _, text in block_files:
        data = json.loads(text)["minecraft:block"]
        components = data.get("components", {})
        tags = components.get("minecraft:tags", [])
        tool = next((tag.split("_")[1] for tag in tags if re.fullmatch(r"minecraft:is_\w+_item_destructible", tag)), None)
        requires_tool = data["description"].get("internal_vanilla_data", {}).get("requires_correct_tool_for_drops", False)
        hardness = components.get("minecraft:destructible_by_mining", {}).get("seconds_to_destroy", 0)
        mojang[data["description"]["identifier"]] = (hardness, tool, "wooden" if requires_tool and tool not in (None, "shears") else None, None)

    paths = {name.partition(":")[2]: name for name in blocks}
    direct = {}

    for path in paths:
        if "minecraft:" + path in mojang:
            direct[path] = mojang["minecraft:" + path]
        else:
            rule = match_rule(path)

            if rule is not None:
                direct[path] = rule

    info = {}
    unmatched = []

    for path, name in paths.items():
        rule = direct.get(path)

        if rule is None or path.endswith(SHAPE_SUFFIXES):
            base = block_base(path, direct)

            if base is not None and (rule is None or "minecraft:" + path not in mojang):
                hardness, tool, tier, _ = direct[base]
                rule = (hardness, tool, tier, None)

        if rule is None:
            unmatched.append(path)
            rule = (0, None, None, None)

        hardness, tool, tier, drops = rule

        if drops is None and path.endswith("_double_slab"):
            slab = name.replace("_double_slab", "_slab")
            drops = [{"item": slab, "min": 2, "max": 2}] if slab in items else NOTHING

        if drops == "leaves":
            drops = leaf_drops(path, items)
        elif drops == "unlit":
            drops = [drop(path.removeprefix("lit_"))]
        elif drops == "sign":
            drops = [drop(sign_item(path))]

        if drops is None:
            drops = [{"item": name, "min": 1, "max": 1}] if name in items else NOTHING

        for entry in drops:
            if entry["item"] not in items:
                fail(f"block {name} drops unknown item {entry['item']}")

        info[name] = {"hardness": hardness, "tool": tool, "tier": tier, "drops": drops}

    return info, unmatched


def sign_item(path):
    if path.endswith("_hanging_sign"):
        return path

    wood = path.removesuffix("standing_sign").removesuffix("wall_sign").removesuffix("_")

    return {"": "oak", "darkoak": "dark_oak"}.get(wood, wood) + "_sign"


def leaf_drops(path, items):
    wood = path.removesuffix("_leaves").removesuffix("_leaves_flowered")
    drops = []

    for sapling in (wood + "_sapling", wood + "_propagule", "flowering_" + wood if path.endswith("_flowered") else wood):
        if "minecraft:" + sapling in items and sapling != "leaves":
            drops.append(drop(sapling, 1, 1, 0.05))
            break

    drops.append(drop("stick", 1, 2, 0.02))

    if wood in ("oak", "dark_oak"):
        drops.append(drop("apple", 1, 1, 0.005))

    return drops


def breaking_json(version, info):
    entries = [f"    {json.dumps(name)}: {json.dumps(data, separators=(', ', ': '))}" for name, data in sorted(info.items())]

    return "{\n" + f'  "version": {json.dumps(version)},\n  "blocks": {{\n' + ",\n".join(entries) + "\n  }\n}\n"


ITEM_BLOCKS_JSON = ROOT / "resources" / "vanilla" / "item_blocks.json"

ITEM_BLOCKS = {
    "wheat_seeds": ("wheat", None),
    "pumpkin_seeds": ("pumpkin_stem", None),
    "melon_seeds": ("melon_stem", None),
    "beetroot_seeds": ("beetroot", None),
    "torchflower_seeds": ("torchflower_crop", None),
    "pitcher_pod": ("pitcher_crop", None),
    "carrot": ("carrots", None),
    "potato": ("potatoes", None),
    "sweet_berries": ("sweet_berry_bush", None),
    "glow_berries": ("cave_vines", None),
    "cocoa_beans": ("cocoa", None),
    "sugar_cane": ("reeds", None),
    "redstone": ("redstone_wire", None),
    "string": ("trip_wire", None),
    "repeater": ("unpowered_repeater", None),
    "comparator": ("unpowered_comparator", None),
    "banner": ("standing_banner", "wall_banner"),
    "oak_sign": ("standing_sign", "wall_sign"),
    "dark_oak_sign": ("darkoak_standing_sign", "darkoak_wall_sign"),
}

SIGN_WOODS = ("spruce", "birch", "jungle", "acacia", "mangrove", "cherry", "pale_oak", "bamboo", "crimson", "warped", "poplar")

CORAL_FANS = ("tube", "brain", "bubble", "fire", "horn")

NOT_PLACEABLE = ("wheat", "beetroot")


def read_item_blocks(blocks, items):
    table = dict(ITEM_BLOCKS)

    for wood in SIGN_WOODS:
        table[f"{wood}_sign"] = (f"{wood}_standing_sign", f"{wood}_wall_sign")

    for coral in CORAL_FANS:
        for prefix in ("", "dead_"):
            table[f"{prefix}{coral}_coral_fan"] = (f"{prefix}{coral}_coral_fan", f"{prefix}{coral}_coral_wall_fan")

    placing = {}

    for item, (block, wall) in sorted(table.items()):
        if "minecraft:" + item not in items:
            fail(f"item_blocks: unknown item {item}")

        for name in (block, wall):
            if name is not None and "minecraft:" + name not in blocks:
                fail(f"item_blocks: unknown block {name}")

        entry = {"block": "minecraft:" + block}

        if wall is not None:
            entry["wall"] = "minecraft:" + wall

        placing["minecraft:" + item] = entry

    for item in NOT_PLACEABLE:
        if "minecraft:" + item not in items:
            fail(f"item_blocks: unknown item {item}")

    return placing, ["minecraft:" + item for item in NOT_PLACEABLE]


def item_blocks_json(version, placing, not_placeable):
    entries = [f"    {json.dumps(item)}: {json.dumps(entry, separators=(', ', ': '))}" for item, entry in placing.items()]

    return "{\n" + f'  "version": {json.dumps(version)},\n  "items": {{\n' + ",\n".join(entries) + "\n  },\n" + f'  "not_placeable": {json.dumps(not_placeable)}\n' + "}\n"


PARTICLE_EFFECT_PHP = ROOT / "src" / "raspberry" / "network" / "mcpe" / "protocol" / "types" / "ParticleEffect.php"
LEVEL_EVENT_PHP = ROOT / "src" / "raspberry" / "network" / "mcpe" / "protocol" / "types" / "LevelEvent.php"
PARTICLE_TYPE_PHP = ROOT / "src" / "raspberry" / "network" / "mcpe" / "protocol" / "types" / "ParticleType.php"

LEVEL_EVENTS = {
    "sound_click": 1000, "sound_click_fail": 1001, "sound_launch": 1002, "sound_open_door": 1003, "sound_fizz": 1004,
    "sound_fuse": 1005, "sound_play_recording": 1006, "sound_ghast_warning": 1007, "sound_ghast_fireball": 1008,
    "sound_blaze_fireball": 1009, "sound_zombie_wooden_door": 1010, "sound_zombie_door_crash": 1012,
    "sound_zombie_infected": 1016, "sound_zombie_converted": 1017, "sound_enderman_teleport": 1018,
    "sound_anvil_broken": 1020, "sound_anvil_used": 1021, "sound_anvil_land": 1022,
    "sound_infinity_arrow_pickup": 1030, "sound_teleport_ender_pearl": 1032, "sound_add_item": 1040,
    "sound_item_frame_break": 1041, "sound_item_frame_place": 1042, "sound_item_frame_remove_item": 1043,
    "sound_item_frame_rotate_item": 1044, "sound_experience_orb_pickup": 1051, "sound_totem_used": 1052,
    "sound_armor_stand_break": 1060, "sound_armor_stand_hit": 1061, "sound_armor_stand_land": 1062,
    "sound_armor_stand_place": 1063, "sound_pointed_dripstone_land": 1064, "sound_dye_used": 1065,
    "sound_ink_sac_used": 1066, "sound_amethyst_resonate": 1067, "queue_custom_music": 1900,
    "play_custom_music": 1901, "stop_custom_music": 1902, "set_music_volume": 1903, "particles_shoot": 2000,
    "particles_destroy_block": 2001, "particles_potion_splash": 2002, "particles_eye_of_ender_death": 2003,
    "particles_mob_block_spawn": 2004, "particle_crop_growth": 2005, "particle_sound_guardian_ghost": 2006,
    "particle_death_smoke": 2007, "particle_deny_block": 2008, "particle_generic_spawn": 2009,
    "particles_dragon_egg": 2010, "particles_crop_eaten": 2011, "particles_critical": 2012,
    "particles_teleport": 2013, "particles_crack_block": 2014, "particles_bubble": 2015, "particles_evaporate": 2016,
    "particles_destroy_armor_stand": 2017, "particles_breaking_egg": 2018, "particle_destroy_egg": 2019,
    "particles_evaporate_water": 2020, "particles_destroy_block_no_sound": 2021, "particles_knockback_roar": 2022,
    "particles_teleport_trail": 2023, "particles_point_cloud": 2024, "particles_explosion": 2025,
    "particles_block_explosion": 2026, "particles_vibration_signal": 2027, "particles_dripstone_drip": 2028,
    "particles_fizz_effect": 2029, "wax_on": 2030, "wax_off": 2031, "scrape": 2032, "particles_electric_spark": 2033,
    "particle_turtle_egg": 2034, "particle_sculk_shriek": 2035, "sculk_catalyst_bloom": 2036, "sculk_charge": 2037,
    "sculk_charge_pop": 2038, "sonic_explosion": 2039, "dust_plume": 2040, "start_raining": 3001,
    "start_thunderstorm": 3002, "stop_raining": 3003, "stop_thunderstorm": 3004, "global_pause": 3005,
    "sim_time_step": 3006, "sim_time_scale": 3007, "activate_block": 3500, "cauldron_explode": 3501,
    "cauldron_dye_armor": 3502, "cauldron_clean_armor": 3503, "cauldron_fill_potion": 3504,
    "cauldron_take_potion": 3505, "cauldron_fill_water": 3506, "cauldron_take_water": 3507, "cauldron_add_dye": 3508,
    "cauldron_clean_banner": 3509, "cauldron_flush": 3510, "agent_spawn_effect": 3511, "cauldron_fill_lava": 3512,
    "cauldron_take_lava": 3513, "cauldron_fill_powder_snow": 3514, "cauldron_take_powder_snow": 3515,
    "start_block_cracking": 3600, "stop_block_cracking": 3601, "update_block_cracking": 3602,
    "particles_crack_block_down": 3603, "particles_crack_block_up": 3604, "particles_crack_block_north": 3605,
    "particles_crack_block_south": 3606, "particles_crack_block_west": 3607, "particles_crack_block_east": 3608,
    "particles_shoot_white_smoke": 3609, "particles_breeze_wind_explosion": 3610,
    "particles_trial_spawner_detection": 3611, "particles_trial_spawner_spawning": 3612,
    "particles_trial_spawner_ejecting": 3613, "particles_wind_explosion": 3614,
    "particles_trial_spawner_detection_charged": 3615, "particles_trial_spawner_become_charged": 3616,
    "all_players_sleeping": 9800, "sleeping_players": 9801, "jump_prevented": 9810, "animation_vault_activate": 9811,
    "animation_vault_deactivate": 9812, "animation_vault_eject_item": 9813, "animation_spawn_cobweb": 9814,
    "particle_smash_attack_ground_dust": 9815, "particle_creaking_heart_trail": 9816, "particle_legacy_event": 0x4000
}

PARTICLE_TYPES = [
    "undefined", "bubble", "bubble_manual", "crit", "block_force_field", "smoke", "explode", "evaporation", "flame",
    "lava", "large_smoke", "red_dust", "rising_border_dust", "icon_crack", "snowball_poof", "large_explode",
    "huge_explosion", "mob_flame", "heart", "terrain", "town_aura", "portal", "mob_portal", "water_splash",
    "water_splash_manual", "water_wake", "drip_water", "drip_lava", "drip_honey", "stalactite_drip_water",
    "stalactite_drip_lava", "falling_dust", "mob_spell", "mob_spell_ambient", "mob_spell_instantaneous", "ink",
    "slime", "rain_splash", "villager_angry", "villager_happy", "enchanting_table", "tracker_emitter", "note",
    "witch_spell", "carrot_boost", "mob_appearance", "end_rod", "dragon_breath", "spit", "totem", "food",
    "fireworks_starter", "fireworks", "fireworks_overlay", "balloon_gas", "coloured_flame", "sparkler", "conduit",
    "bubble_column_up", "bubble_column_down", "sneeze", "shulker_bullet", "bleach", "dragon_destroy_block",
    "mycelium_dust", "falling_border_dust", "campfire_smoke", "campfire_smoke_tall", "dragon_breath_fire",
    "dragon_breath_trail", "blue_flame", "soul", "obsidian_tear", "portal_reverse", "snowflake", "vibration_signal",
    "sculk_sensor_redstone", "spore_blossom_shower", "spore_blossom_ambient", "wax", "electric_spark", "candle_flame",
    "shriek", "sculk_soul", "sonic_explosion", "brush_dust", "cherry_leaves", "dust_plume", "white_smoke",
    "wind_explosion", "breeze_wind_explosion", "vault_connection", "wolf_armour_break", "ominous_item_spawner",
    "creaking_crumble", "pale_oak_leaves", "eyeblossom_open", "eyeblossom_close", "green_flame", "pause_mob_growth",
    "reset_mob_growth", "sulfur_cube", "orange_poplar_leaves", "red_poplar_leaves", "yellow_poplar_leaves"
]


def read_particle_effects(files):
    names = []

    for file_name, text in files:
        match = re.search(r'"identifier"\s*:\s*"([a-z0-9_]+:[a-z0-9_.]+)"', text)

        if match is None:
            fail(f"particle file {file_name} has no identifier")

        names.append(match.group(1))

    return sorted(set(names))


def particle_const_name(name):
    return re.sub(r"[^A-Z0-9]", "_", name.partition(":")[2].upper())


def snake_const_name(name):
    return name.upper()


FURNACE_DATA_JSON = ROOT / "resources" / "vanilla" / "furnace_data.json"

WOODS = r"(oak|spruce|birch|jungle|acacia|dark_oak|mangrove|cherry|pale_oak|bamboo|bamboo_mosaic)"
COLORS = r"(white|orange|magenta|light_blue|yellow|lime|pink|gray|light_gray|cyan|purple|blue|brown|green|red|black)"

FUEL_RULES = [
    (r"_double_slab$", None),
    (r"^lava_bucket$", 20000),
    (r"^coal_block$", 16000),
    (r"^dried_kelp_block$", 4000),
    (r"^blaze_rod$", 2400),
    (r"^(coal|charcoal)$", 1600),
    (rf"^{WOODS}_(chest_)?(boat|raft)$", 1200),
    (rf"^{WOODS}_hanging_sign$", 800),
    (rf"^(stripped_)?{WOODS}_(log|wood)$|^(stripped_)?bamboo_block$", 300),
    (rf"^{WOODS}_(planks|stairs|fence|fence_gate|trapdoor|pressure_plate|shelf)$", 300),
    (r"^(fence_gate|wooden_pressure_plate|trapdoor)$", 300),
    (r"^(chest|trapped_chest|crafting_table|bookshelf|chiseled_bookshelf|lectern|jukebox|noteblock|barrel|loom|composter|cartography_table|fletching_table|smithing_table|daylight_detector|banner|bow|crossbow|fishing_rod|ladder|mangrove_roots|beehive|bee_nest)$", 300),
    (rf"^{WOODS}_slab$", 150),
    (rf"^(wooden_door|{WOODS}_door|{WOODS}_sign|bowl|wooden_(sword|shovel|pickaxe|axe|hoe))$", 200),
    (rf"^(stick|{WOODS}_sapling|{COLORS}_wool|deadbush|{WOODS}_button|wooden_button|azalea|flowering_azalea|mangrove_propagule)$", 100),
    (rf"^{COLORS}_carpet$", 67),
    (r"^(bamboo|scaffolding)$", 50),
]

SMELT_EXPERIENCE_RULES = [
    (r"^(diamond|emerald|gold_ingot|green_dye)$", 1.0),
    (r"^netherite_scrap$", 2.0),
    (r"^(iron_ingot|copper_ingot|redstone)$", 0.7),
    (r"^(cooked_\w+|baked_potato|dried_kelp)$|^(hardened_clay|\w+_terracotta)$", 0.35),
    (r"^(brick)$", 0.3),
    (r"^(lapis_lazuli|quartz)$", 0.2),
    (r"^(charcoal|sponge)$", 0.15),
]


def read_furnace_data(items, recipes):
    fuels = {}

    for name in sorted(items):
        path = name.partition(":")[2]

        for pattern, ticks in FUEL_RULES:
            if re.search(pattern, path) is not None:
                if ticks is not None:
                    fuels[name] = ticks
                break

    experience = {}

    for recipe in recipes.get("furnace", []):
        output = recipe["output"]["item"]
        path = output.partition(":")[2]
        experience[output] = next((xp for pattern, xp in SMELT_EXPERIENCE_RULES if re.search(pattern, path) is not None), 0.1)

    return fuels, dict(sorted(experience.items()))


def furnace_data_json(version, fuels, experience):
    return json.dumps({"version": version, "fuels": fuels, "experience": experience}, indent=2) + "\n"


def protocol_docs_version(version):
    return ".".join(version.split(".")[:3])


def load_sound_events(args):
    if args.input is not None:
        with open(Path(args.input) / "LevelSoundEvent.json", "r", encoding="utf-8-sig") as handle:
            data = json.load(handle)
    else:
        url = SOUND_EVENTS_URL.format(version=protocol_docs_version(args.version))
        print(f"Downloading {url}")
        data = json.loads(download_text(url))

    names = data.get("enum")

    if not isinstance(names, list) or not all(isinstance(name, str) for name in names):
        fail("LevelSoundEvent.json has no list of sound names")

    return data.get("x-protocol-version"), names


def sound_const_name(name):
    return re.sub(r"[^A-Z0-9]", "_", name.upper())


def main():
    parser = argparse.ArgumentParser(description="Generate RaspberryX block and biome data from Mojang bedrock-samples")
    parser.add_argument("--version", required=True, help="bedrock-samples release tag without the v, e.g. 1.26.60.5")
    parser.add_argument("--input", help="use a local vanilladata_modules folder instead of downloading")
    args = parser.parse_args()

    block_source = load_source(args, "mojang-blocks.json")
    biome_source = load_source(args, "mojang-biomes.json")
    item_source = load_source(args, "mojang-items.json")
    potion_source = load_source(args, "mojang-potion-effects.json")
    lang = load_lang(args)

    properties = read_properties(block_source)
    blocks = read_blocks(block_source, properties)
    biomes = read_biomes(biome_source)
    items = read_items(item_source, block_source)
    item_names, guessed_names = read_item_names(item_source, block_source, items, lang)
    creative_items = read_creative_items(item_source, block_source)
    item_tags, recipes, skipped_recipes = read_recipes(load_recipe_files(args), items, potion_source)
    breaking, unmatched_blocks = read_block_breaking(blocks, items, load_block_files(args))
    sound_protocol, sound_events = load_sound_events(args)
    item_blocks, not_placeable = read_item_blocks(blocks, items)
    particle_effects = read_particle_effects(load_pack_files(args, "particles", "resource_pack"))

    used_properties = sorted({property_name for states in blocks.values() for property_name in states})

    block_constants = build_constants(blocks.keys(), block_const_name)
    property_constants = build_constants(used_properties, property_const_name)
    biome_constants = {const: biomes[name] for const, name in build_constants(biomes.keys(), block_const_name).items()}
    item_constants = build_constants(items.keys(), block_const_name)
    sound_constants = build_constants(sound_events, sound_const_name)
    particle_constants = build_constants(particle_effects, particle_const_name)
    level_event_constants = {const: LEVEL_EVENTS[name] for const, name in build_constants(LEVEL_EVENTS.keys(), snake_const_name).items()}
    particle_type_constants = {const: PARTICLE_TYPES.index(name) for const, name in build_constants(PARTICLE_TYPES, snake_const_name).items()}

    write(BLOCK_IDS_PHP, php_class("raspberry\\block", "BlockIds", "Names of every vanilla block.", args.version, block_constants))
    write(BLOCK_PROPERTIES_PHP, php_class("raspberry\\block", "BlockProperties", "Names of every vanilla block state property.", args.version, property_constants))
    write(BLOCK_STATES_JSON, states_json(args.version, blocks))
    write(ITEM_REGISTRY_JSON, items_json(args.version, items))
    write(ITEM_NAMES_JSON, names_json(args.version, item_names))
    write(CREATIVE_ITEMS_JSON, creative_json(args.version, creative_items))
    write(RECIPES_JSON, recipes_json(args.version, item_tags, recipes))
    write(BLOCK_BREAKING_JSON, breaking_json(args.version, breaking))
    write(ITEM_BLOCKS_JSON, item_blocks_json(args.version, item_blocks, not_placeable))
    write(FURNACE_DATA_JSON, furnace_data_json(args.version, *read_furnace_data(items, recipes)))
    write(ITEM_IDS_PHP, php_class("raspberry\\item", "ItemIds", "Names of every vanilla item, including blocks in item form.", args.version, item_constants))
    write(BIOME_IDS_PHP, php_class("raspberry\\world\\biome", "BiomeIds", "Numeric IDs of every vanilla biome.", args.version, biome_constants))
    write(PARTICLE_EFFECT_PHP, php_class("raspberry\\network\\mcpe\\protocol\\types", "ParticleEffect", "Names of every particle effect for SpawnParticleEffectPacket.", args.version, particle_constants))
    write(LEVEL_EVENT_PHP, php_class("raspberry\\network\\mcpe\\protocol\\types", "LevelEvent", "IDs of the level events for LevelEventPacket (sounds, particles, block cracking...).", args.version, level_event_constants, None))
    write(PARTICLE_TYPE_PHP, php_class("raspberry\\network\\mcpe\\protocol\\types", "ParticleType", "IDs of the old numbered particles, sent as LevelEvent::PARTICLE_LEGACY_EVENT | ID.", args.version, particle_type_constants, None))
    write(SOUND_EVENTS_PHP, php_class("raspberry\\network\\mcpe\\protocol\\types", "LevelSoundEvent", f"Names of every sound event for LevelSoundEventPacket (protocol {sound_protocol}).", protocol_docs_version(args.version), sound_constants, "bedrock-protocol-docs"))

    permutations = 0

    for states in blocks.values():
        count = 1

        for values in states.values():
            count *= len(values)

        permutations += count

    recipe_count = sum(len(entries) for entries in recipes.values())

    print(f"{len(blocks)} blocks, {len(used_properties)} properties, {permutations} block states, {len(biomes)} biomes, {len(items)} items ({guessed_names} names made from the ID), {len(creative_items)} creative items, {recipe_count} recipes ({skipped_recipes} old ones skipped), {len(sound_events)} sound events, {len(particle_effects)} particle effects")

    if unmatched_blocks:
        print(f"{len(unmatched_blocks)} blocks have no breaking rule and break at once: {', '.join(unmatched_blocks)}")

if __name__ == "__main__":
    main()