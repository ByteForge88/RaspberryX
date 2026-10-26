#!/usr/bin/env python3
"""
Generates RaspberryX block data from Mojang's official bedrock-samples repository
(https://github.com/Mojang/bedrock-samples), file metadata/vanilladata_modules/mojang-blocks.json.

Outputs:
  src/raspberry/block/BlockIds.php          one constant per block name
  src/raspberry/block/BlockProperties.php   one constant per block property name
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

ROOT = Path(__file__).resolve().parent.parent

BLOCK_IDS_PHP = ROOT / "src" / "raspberry" / "block" / "BlockIds.php"
BLOCK_PROPERTIES_PHP = ROOT / "src" / "raspberry" / "block" / "BlockProperties.php"
BLOCK_STATES_JSON = ROOT / "resources" / "vanilla" / "block_states.json"
BIOME_IDS_PHP = ROOT / "src" / "raspberry" / "world" / "biome" / "BiomeIds.php"
ITEM_REGISTRY_JSON = ROOT / "resources" / "vanilla" / "item_registry.json"

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
        f"final class {class_name}{{",
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

    properties = read_properties(block_source)
    blocks = read_blocks(block_source, properties)
    biomes = read_biomes(biome_source)
    items = read_items(item_source, block_source)

    used_properties = sorted({property_name for states in blocks.values() for property_name in states})

    block_constants = build_constants(blocks.keys(), block_const_name)
    property_constants = build_constants(used_properties, property_const_name)
    biome_constants = {const: biomes[name] for const, name in build_constants(biomes.keys(), block_const_name).items()}

    write(BLOCK_IDS_PHP, php_class("raspberry\\block", "BlockIds", "Names of every vanilla block.", args.version, block_constants))
    write(BLOCK_PROPERTIES_PHP, php_class("raspberry\\block", "BlockProperties", "Names of every vanilla block state property.", args.version, property_constants))
    write(BLOCK_STATES_JSON, states_json(args.version, blocks))
    write(ITEM_REGISTRY_JSON, items_json(args.version, items))
    write(BIOME_IDS_PHP, php_class("raspberry\\world\\biome", "BiomeIds", "Numeric IDs of every vanilla biome.", args.version, biome_constants))

    permutations = 0

    for states in blocks.values():
        count = 1

        for values in states.values():
            count *= len(values)

        permutations += count

        print(f"{len(blocks)} blocks, {len(used_properties)} properties, {permutations} block states, {len(biomes)} biomes, {len(items)} items")


if __name__ == "__main__":
    main()