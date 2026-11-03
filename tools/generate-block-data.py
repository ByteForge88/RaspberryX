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
  resources/vanilla/recipes.json            every recipe from behavior_pack/recipes, old item names upgraded

Usage:
  python tools/generate-block-data.py --version 1.26.60.5          (downloads from GitHub)
    python tools/generate-block-data.py --input path/to/folder --version 1.26.60.5
        (the folder holds the mojang-*.json files, en_US.lang and a "recipes" folder)
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


RECIPES_JSON = ROOT / "resources" / "vanilla" / "recipes.json"
RECIPES_TREE_URL = "https://api.github.com/repos/Mojang/bedrock-samples/git/trees/v{version}?recursive=1"
RECIPES_FILE_URL = "https://raw.githubusercontent.com/Mojang/bedrock-samples/v{version}/{path}"

# old item names (and data values) in Mojang's recipe files and the items they are now, Mojang
# publishes no table for this, the game upgrades them internally
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

# old block names that matched any variant: the recipes using them were replaced by ones with item tags
RECIPE_OBSOLETE_ITEMS = {"minecraft:log", "minecraft:log2", "minecraft:wood"}

# items whose data value still picks a variant; for every other item the data value is ignored
RECIPE_META_ITEMS = {"minecraft:banner", "minecraft:suspicious_stew", "minecraft:empty_map", "minecraft:potion", "minecraft:splash_potion", "minecraft:lingering_potion"}

# potion data values (Mojang lists the potion names in mojang-potion-effects.json, not their numbers)
POTION_TYPES = [
    "water", "mundane", "long_mundane", "thick", "awkward", "nightvision", "long_nightvision", "invisibility", "long_invisibility", "leaping",
    "long_leaping", "strong_leaping", "fire_resistance", "long_fire_resistance", "swiftness", "long_swiftness", "strong_swiftness", "slowness",
    "long_slowness", "water_breathing", "long_water_breathing", "healing", "strong_healing", "harming", "strong_harming", "poison", "long_poison",
    "strong_poison", "regeneration", "long_regeneration", "strong_regeneration", "strength", "long_strength", "strong_strength", "weakness",
    "long_weakness", "wither", "turtle_master", "long_turtle_master", "strong_turtle_master", "slow_falling", "long_slow_falling", "strong_slowness",
    "wind_charged", "weaving", "oozing", "infested",
]

# members of the item tags recipes use (the game has them built in), by item name pattern or list
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
    """Downloads a text file, trying again a few times when GitHub drops the connection."""
    for attempt in range(1, attempts + 1):
        try:
            with urllib.request.urlopen(url, timeout=60) as response:
                return response.read().decode("utf-8-sig")
        except (urllib.error.URLError, ConnectionError, TimeoutError) as error:
            if attempt == attempts:
                fail(f"cannot download {url}: {error}")

            time.sleep(attempt * 2)


def load_recipe_files(args):
    """Returns [(file name, recipe JSON)] from behavior_pack/recipes."""
    files = []

    if args.input is not None:
        for path in sorted((Path(args.input) / "recipes").glob("*.json")):
            with open(path, "r", encoding="utf-8-sig") as handle:
                files.append((path.name, handle.read()))

        return files

    from concurrent.futures import ThreadPoolExecutor

    url = RECIPES_TREE_URL.format(version=args.version)
    print(f"Downloading {url}")

    tree = json.loads(download_text(url))
    paths = sorted(entry["path"] for entry in tree["tree"] if entry["path"].startswith("behavior_pack/recipes/") and entry["path"].endswith(".json"))

    def download(path):
        return path.rsplit("/", 1)[-1], download_text(RECIPES_FILE_URL.format(version=args.version, path=path))

    print(f"Downloading {len(paths)} recipe files")

    with ThreadPoolExecutor(4) as pool:
        return list(pool.map(download, paths))


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
    """Upgrades an old item name. Returns (name, data) with data None meaning any data value."""
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
    """
    Every recipe that is not tagged "deprecated", grouped by type, with old item names upgraded.
    Recipes using old names that matched any variant are skipped (newer recipes with tags replace them).
    """
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



def main():
    parser = argparse.ArgumentParser(description="Generate RaspberryX block and biome data from Mojang bedrock-samples")
    parser.add_argument("--version", required=True, help="bedrock-samples release tag without the v, e.g. 1.26.50.4")
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
    write(RECIPES_JSON, recipes_json(args.version, item_tags, recipes))
    write(ITEM_IDS_PHP, php_class("raspberry\\item", "ItemIds", "Names of every vanilla item, including blocks in item form.", args.version, item_constants))
    write(BIOME_IDS_PHP, php_class("raspberry\\world\\biome", "BiomeIds", "Numeric IDs of every vanilla biome.", args.version, biome_constants))

    permutations = 0

    for states in blocks.values():
        count = 1

        for values in states.values():
            count *= len(values)

        permutations += count

    recipe_count = sum(len(entries) for entries in recipes.values())

    print(f"{len(blocks)} blocks, {len(used_properties)} properties, {permutations} block states, {len(biomes)} biomes, {len(items)} items ({guessed_names} names made from the ID), {len(creative_items)} creative items, {recipe_count} recipes ({skipped_recipes} old ones skipped)")

if __name__ == "__main__":
    main()