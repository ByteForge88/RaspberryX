#!/usr/bin/env python3
"""
Generates RaspberryX block data from Mojang's official bedrock-samples repository
(https://github.com/Mojang/bedrock-samples), file metadata/vanilladata_modules/mojang-blocks.json.

Outputs:
  src/raspberry/block/BlockIds.php          one constant per block name
  src/raspberry/block/BlockProperties.php   one constant per block property name
  src/raspberry/item/ItemIds.php            one constant per item name (blocks that can be items included)
  resources/vanilla/item_names.json         the English name of every item, from resource_pack/texts/en_US.lang
  resources/vanilla/creative_items.json     the creative inventory: every survival item, sorted into its tab
  resources/vanilla/block_states.json       every block, its properties and allowed values

Usage:
  python tools/generate-block-data.py --version 1.26.50.4          (downloads from GitHub)
  python tools/generate-block-data.py --input path/to/mojang-blocks.json --version 1.26.50.4
"""

import argparse
import json
import re
import sys
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

# names Mojang's language file has no usable key for
ITEM_NAME_OVERRIDES = {
    "minecraft:undyed_shulker_box": "Shulker Box",
    "minecraft:normal_stone_slab": "Stone Slab",
    "minecraft:disc_fragment_5": "Disc Fragment",
    "minecraft:stone_brick_slab": "Stone Brick Slab",
}

# words that only group variants in old language keys, e.g. tile.wooden_slab.acacia.name = Acacia Slab
LANG_GROUP_WORDS = {"cobblestone", "stone", "wooden", "flower", "tallgrass"}

# old words in language keys and the words item names use instead
LANG_WORD_ALIASES = {"silver": "light_gray", "big": "dark"}

SMALL_WORDS = {"of", "the", "and", "with", "on", "a", "in", "to"}

CREATIVE_ITEMS_JSON = ROOT / "resources" / "vanilla" / "creative_items.json"

# operator-only and technical items that vanilla keeps out of the creative inventory
CREATIVE_EXCLUDED = re.compile(r"^(air|allow|deny|border_block|barrier|jigsaw|structure_block|structure_void|command_block|chain_command_block|repeating_command_block|command_block_minecart|light_block_\d+|frosted_ice|stonecutter_block|ender_dragon_spawn_egg|wither_spawn_egg|npc_spawn_egg|agent_spawn_egg)$")

CREATIVE_EQUIPMENT = re.compile(r"_(sword|shovel|pickaxe|axe|hoe|spear|helmet|chestplate|leggings|boots|horse_armor|nautilus_armor|harness)$|^(bow|crossbow|trident|mace|shield|elytra|arrow|wolf_armor|totem_of_undying|fishing_rod|shears|flint_and_steel|carrot_on_a_stick|warped_fungus_on_a_stick|brush|saddle|lead|spyglass|compass|recovery_compass|clock|firework_rocket|wind_charge)$")

CREATIVE_NATURE = re.compile(r"_(log|wood|hyphae|stem|leaves|sapling|propagule|ore|tulip|orchid|coral|coral_block|coral_fan|mushroom|mushroom_block|fungus|roots|nylium|petals|dripleaf|bush|vines|egg)$|^(dirt|coarse_dirt|rooted_dirt|dirt_with_roots|grass_block|podzol|mycelium|mud|clay|gravel|sand|red_sand|snow|snow_layer|ice|packed_ice|blue_ice|netherrack|soul_sand|soul_soil|end_stone|obsidian|crying_obsidian|bedrock|stone|deepslate|tuff|calcite|granite|diorite|andesite|basalt|blackstone|dripstone_block|pointed_dripstone|moss_block|moss_carpet|pale_moss_block|pale_moss_carpet|allium|azure_bluet|cornflower|dandelion|poppy|lily_of_the_valley|oxeye_daisy|wither_rose|peony|lilac|rose_bush|sunflower|fern|large_fern|short_grass|tall_grass|deadbush|seagrass|kelp|vine|cactus|bamboo|sugar_cane|pumpkin|carved_pumpkin|melon_block|hay_block|nether_wart_block|warped_wart_block|shroomlight|bee_nest|sculk|sculk_vein|sculk_catalyst|sculk_shrieker|sculk_sensor|amethyst_block|budding_amethyst|amethyst_cluster|azalea|flowering_azalea|spore_blossom|glow_lichen|sponge|wet_sponge|magma|glowstone|waterlily|frog_spawn|sniffer_egg|torchflower|pitcher_plant|chorus_plant|chorus_flower|crimson_roots|warped_roots|twisting_vines|weeping_vines|mangrove_roots|muddy_mangrove_roots|big_dripleaf|small_dripleaf_block|hanging_roots|cave_vines|honeycomb_block|snow_block|powder_snow|eyeblossom|open_eyeblossom|closed_eyeblossom|firefly_bush|leaf_litter|cactus_flower|resin_clump)$")

# items whose data value picks a variant: every value gets its own creative entry
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
    """Returns {property name: [allowed values]} with values checked against the declared type."""
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
    """Returns {block name: {property name: [allowed values]}}, sorted by name."""
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
    """Returns {biome name: numeric ID}."""
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
    """Returns {translation key: English text} from en_US.lang."""
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
    """The words of a language key or item name: tile.planks.acacia.name and acacia_planks both give {acacia, planks}."""
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
    """
    Finds each item's English name. In order: the item's own key, item.<id>.name or tile.<id>.name,
    a key with the same words (tile.planks.acacia.name for acacia_planks), an old grouped key
    (tile.wooden_slab.acacia.name), a few rules, and finally the ID in title case.
    """
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

        # old dye keys name black dye "Ink Sac", blue dye "Lapis Lazuli" and so on
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
    """
    Every item that survival players can get, in one of the four creative tabs (construction,
    nature, equipment, items). Within a tab, items are grouped by their last word (all planks,
    then all slabs...) and sorted by name inside a group.
    """
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


def php_class(namespace, class_name, description, version, constants):
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
        f" * Generated by tools/generate-block-data.py from Mojang bedrock-samples v{version}.",
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


def main():
    parser = argparse.ArgumentParser(description="Generate RaspberryX block and biome data from Mojang bedrock-samples")
    parser.add_argument("--version", required=True, help="bedrock-samples release tag without the v, e.g. 1.26.50.4")
    parser.add_argument("--input", help="use a local vanilladata_modules folder instead of downloading")
    args = parser.parse_args()

    block_source = load_source(args, "mojang-blocks.json")
    biome_source = load_source(args, "mojang-biomes.json")
    item_source = load_source(args, "mojang-items.json")
    lang = load_lang(args)

    properties = read_properties(block_source)
    blocks = read_blocks(block_source, properties)
    biomes = read_biomes(biome_source)
    items = read_items(item_source, block_source)
    item_names, guessed_names = read_item_names(item_source, block_source, items, lang)
    creative_items = read_creative_items(item_source, block_source)

    used_properties = sorted({property_name for states in blocks.values() for property_name in states})

    block_constants = build_constants(blocks.keys(), block_const_name)
    property_constants = build_constants(used_properties, property_const_name)
    biome_constants = {const: biomes[name] for const, name in build_constants(biomes.keys(), block_const_name).items()}
    item_constants = build_constants(items.keys(), block_const_name)

    write(BLOCK_IDS_PHP, php_class("raspberry\\block", "BlockIds", "Names of every vanilla block.", args.version, block_constants))
    write(BLOCK_PROPERTIES_PHP, php_class("raspberry\\block", "BlockProperties", "Names of every vanilla block state property.", args.version, property_constants))
    write(BLOCK_STATES_JSON, states_json(args.version, blocks))
    write(ITEM_REGISTRY_JSON, items_json(args.version, items))
    write(ITEM_NAMES_JSON, names_json(args.version, item_names))
    write(CREATIVE_ITEMS_JSON, creative_json(args.version, creative_items))
    write(ITEM_IDS_PHP, php_class("raspberry\\item", "ItemIds", "Names of every vanilla item, including blocks in item form.", args.version, item_constants))
    write(BIOME_IDS_PHP, php_class("raspberry\\world\\biome", "BiomeIds", "Numeric IDs of every vanilla biome.", args.version, biome_constants))

    permutations = 0

    for states in blocks.values():
        count = 1

        for values in states.values():
            count *= len(values)

            permutations += count

            print(f"{len(blocks)} blocks, {len(used_properties)} properties, {permutations} block states, {len(biomes)} biomes, {len(items)} items ({guessed_names} names made from the ID), {len(creative_items)} creative items")


if __name__ == "__main__":
    main()