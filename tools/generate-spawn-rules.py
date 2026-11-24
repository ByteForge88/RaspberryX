#!/usr/bin/env python3
"""
Generates where, when and how many of each mob vanilla spawns on its own, from Mojang's official
bedrock-samples repository.

Url: https://github.com/Mojang/bedrock-samples

folders behavior_pack/spawn_rules and behavior_pack/entities.

Outputs:
  resources/vanilla/spawn_rules.json   the spawn rules of every mob, and the size of every entity

Usage:
  python tools/generate-spawn-rules.py --version 1.26.60.5

  More advanced:
  python tools/generate-spawn-rules.py --input path/to/folder --version 1.26.60.5

Info:
  the folder holds the "spawn_rules" and "entities" folders of the behavior pack
"""

import argparse
import json
import sys
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

TREE_URL = "https://api.github.com/repos/Mojang/bedrock-samples/git/trees/v{version}?recursive=1"
FILE_URL = "https://raw.githubusercontent.com/Mojang/bedrock-samples/v{version}/{path}"

ROOT = Path(__file__).resolve().parent.parent

SPAWN_RULES_JSON = ROOT / "resources" / "vanilla" / "spawn_rules.json"
BLOCK_STATES_JSON = ROOT / "resources" / "vanilla" / "block_states.json"

POPULATIONS = ["monster", "animal", "water_animal", "ambient", "cat", "pillager"]

DIFFICULTIES = {"peaceful": 0, "easy": 1, "normal": 2, "hard": 3}

PLACES = {
    "minecraft:spawns_on_surface": "surface",
    "minecraft:spawns_underground": "underground",
    "minecraft:spawns_underwater": "underwater",
    "minecraft:spawns_lava": "lava"
}

REQUIREMENTS = {
    "minecraft:mob_event_filter": "mob_event",
    "minecraft:delay_filter": "delay",
    "minecraft:world_age_filter": "world_age",
    "minecraft:player_in_village_filter": "player_in_village"
}

BLOCK_ALIASES = {"minecraft:grass": "minecraft:grass_block"}


def fail(message):
    print(f"error: {message}", file=sys.stderr)
    sys.exit(1)


def download_text(url, attempts=6):
    for attempt in range(1, attempts + 1):
        try:
            with urllib.request.urlopen(url, timeout=60) as response:
                return response.read().decode("utf-8-sig")
        except urllib.error.HTTPError as error:
            if error.code == 404:
                fail(f"{url} does not exist, check that --version is a bedrock-samples release tag")

            if attempt == attempts:
                fail(f"cannot download {url}: {error}")
        except (urllib.error.URLError, ConnectionError, TimeoutError) as error:
            if attempt == attempts:
                fail(f"cannot download {url}: {error}")

        time.sleep(attempt * 2)


def strip_json_comments(text):
    out = []
    index = 0
    in_string = False

    while index < len(text):
        char = text[index]

        if in_string:
            out.append(char)

            if char == "\\":
                out.append(text[index + 1])
                index += 1
            elif char == '"':
                in_string = False
        elif char == '"':
            in_string = True
            out.append(char)
        elif text.startswith("//", index):
            index = text.find("\n", index)

            if index == -1:
                break

            continue
        elif text.startswith("/*", index):
            index = text.find("*/", index) + 2
            continue
        else:
            out.append(char)

        index += 1

    return "".join(out)


def load_folders(args, folders):
    found = {}

    if args.input is not None:
        for folder in folders:
            path = Path(args.input) / folder

            if not path.is_dir():
                fail(f"{path} does not exist")

            found[folder] = [(file.name, file.read_text(encoding="utf-8-sig")) for file in sorted(path.glob("*.json"))]

        return found

    url = TREE_URL.format(version=args.version)
    print(f"Downloading {url}")
    tree = json.loads(download_text(url))["tree"]

    def download(path):
        return path.rsplit("/", 1)[-1], download_text(FILE_URL.format(version=args.version, path=path))

    for folder in folders:
        prefix = f"behavior_pack/{folder}/"
        paths = sorted(entry["path"] for entry in tree if entry["path"].startswith(prefix) and entry["path"].endswith(".json") and "/" not in entry["path"][len(prefix):])

        if not paths:
            fail(f"bedrock-samples v{args.version} has no {prefix} files")

        print(f"Downloading {len(paths)} files from {prefix}")

        with ThreadPoolExecutor(4) as pool:
            found[folder] = list(pool.map(download, paths))

    return found


def load_blocks():
    if not BLOCK_STATES_JSON.is_file():
        fail(f"{BLOCK_STATES_JSON.relative_to(ROOT)} does not exist, run tools/generate-block-data.py first")

    return set(json.loads(BLOCK_STATES_JSON.read_text(encoding="utf-8"))["blocks"])


def read_biome_filter(source, where):
    if isinstance(source, list):
        return collapse("all", [read_biome_filter(entry, where) for entry in source])

    if not isinstance(source, dict):
        fail(f"{where} has a biome filter that is not a list or an object: {source!r}")

    for key, kind in (("all_of", "all"), ("any_of", "any"), ("none_of", "none")):
        if key in source:
            children = source[key] if isinstance(source[key], list) else [source[key]]
            return collapse(kind, [read_biome_filter(entry, where) for entry in children])

    test = source.get("test")
    operator = source.get("operator", "==")

    if operator not in ("==", "!=", "not", "equals", "<>"):
        fail(f"{where} has a biome test with unknown operator {operator}")

    positive = operator in ("==", "equals")

    if test == "has_biome_tag":
        node = {"tag": source["value"]}
    elif test == "is_snow_covered":
        node = {"snow": source.get("value", True) is not False}
    else:
        fail(f"{where} has unknown biome test {test}")

    if not positive:
        node["is"] = False

    return node


def collapse(kind, children):
    if kind != "none" and len(children) == 1:
        return children[0]

    return {kind: children}


def read_blocks(source, blocks, where):
    names = []

    for entry in source if isinstance(source, list) else [source]:
        name = entry["name"] if isinstance(entry, dict) else entry
        name = BLOCK_ALIASES.get(name, name)

        if name not in blocks:
            fail(f"{where} lists unknown block {name}")

        names.append(name)

    return names


def read_range(source, where, low="min", high="max"):
    if not isinstance(source, dict):
        fail(f"{where} has a range that is not an object: {source!r}")

    return {key: source[name] for key, name in (("min", low), ("max", high)) if name in source}


def read_herd(source):
    herd = {"min": source.get("min_size", 1), "max": source.get("max_size", source.get("min_size", 1))}

    if "event" in source:
        herd["event"] = source["event"]
        herd["skip"] = source.get("event_skip_count", 0)

    if "initial_event" in source:
        herd["initial_event"] = source["initial_event"]
        herd["initial_count"] = source.get("initial_event_count", 0)

    return herd


def read_condition(source, blocks, where):
    condition = {"weight": 0, "herds": [{"min": 1, "max": 1}], "places": []}

    for key, value in source.items():
        if key == "minecraft:weight":
            condition["weight"] = value.get("default", 0)

            if "rarity" in value:
                condition["rarity"] = value["rarity"]
        elif key == "minecraft:herd":
            condition["herds"] = [read_herd(entry) for entry in (value if isinstance(value, list) else [value])]
        elif key in PLACES:
            condition["places"].append(PLACES[key])
        elif key == "minecraft:brightness_filter":
            condition["brightness"] = {"min": value.get("min", 0), "max": value.get("max", 15), "weather": value.get("adjust_for_weather", False)}
        elif key == "minecraft:difficulty_filter":
            condition["difficulty"] = {bound: DIFFICULTIES[value[bound]] for bound in ("min", "max") if bound in value}
        elif key == "minecraft:height_filter":
            condition["height"] = read_range(value, where)
        elif key == "minecraft:distance_filter":
            condition["distance"] = read_range(value, where)
        elif key == "minecraft:spawns_on_block_filter":
            condition["on"] = read_blocks(value, blocks, where)
        elif key == "minecraft:spawns_on_block_prevented_filter":
            condition["not_on"] = read_blocks(value, blocks, where)
        elif key == "minecraft:biome_filter":
            condition["biomes"] = read_biome_filter(value, where)
        elif key == "minecraft:density_limit":
            condition["density"] = {place: value[place] for place in ("surface", "underground") if place in value}
        elif key == "minecraft:permute_type":
            condition["permute"] = [read_permutation(entry) for entry in value]
        elif key == "minecraft:spawn_event":
            condition["event"] = value["event"]
        elif key == "minecraft:disallow_spawns_in_bubble":
            condition["no_bubble"] = True
        elif key in REQUIREMENTS:
            condition.setdefault("requires", {})[REQUIREMENTS[key]] = value
        else:
            fail(f"{where} has unknown component {key}")

    if not condition["places"]:
        fail(f"{where} spawns nowhere, it has no spawns_on_surface, spawns_underground, spawns_underwater or spawns_lava")

    return condition


def read_permutation(source):
    entry = {"weight": source.get("weight", 0)}
    name = source.get("entity_type")

    if name is not None:
        name, _, event = name.partition("<")
        entry["type"] = name

        if event:
            entry["event"] = event.rstrip(">")

    return entry


def read_spawn_rules(files, blocks):
    rules = {}

    for file_name, text in files:
        data = json.loads(strip_json_comments(text))["minecraft:spawn_rules"]
        description = data["description"]
        name = description["identifier"]
        population = description["population_control"]

        if population not in POPULATIONS:
            fail(f"{file_name} has unknown population_control {population}")

        conditions = [read_condition(source, blocks, f"{file_name} condition {index + 1}") for index, source in enumerate(data.get("conditions", []))]

        if name in rules:
            if rules[name]["population"] != population:
                fail(f"{file_name} puts {name} in {population}, another file in {rules[name]['population']}")

            rules[name]["conditions"] += conditions
        else:
            rules[name] = {"population": population, "conditions": conditions}

    return dict(sorted(rules.items()))


def read_sizes(files):
    sizes = {}

    for file_name, text in files:
        data = json.loads(strip_json_comments(text))["minecraft:entity"]
        box = data.get("components", {}).get("minecraft:collision_box")

        if box is None:
            for group, components in data.get("component_groups", {}).items():
                if "minecraft:collision_box" in components and "minecraft:is_baby" not in components and "baby" not in group:
                    box = components["minecraft:collision_box"]
                    break

        if box is not None:
            sizes[data["description"]["identifier"]] = [box.get("width", 1.0), box.get("height", 1.0)]

    return dict(sorted(sizes.items()))


def spawn_rules_json(version, rules, sizes):
    rule_entries = [f"    {json.dumps(name)}: {json.dumps(data)}" for name, data in rules.items()]
    size_entries = [f"    {json.dumps(name)}: {json.dumps(size)}" for name, size in sizes.items()]

    return "{\n" + f'  "version": {json.dumps(version)},\n  "rules": {{\n' + ",\n".join(rule_entries) + '\n  },\n  "sizes": {\n' + ",\n".join(size_entries) + "\n  }\n}\n"


def main():
    parser = argparse.ArgumentParser(description="Generate RaspberryX spawn rules from Mojang bedrock-samples")
    parser.add_argument("--version", required=True, help="bedrock-samples release tag without the v, e.g. 1.26.60.5")
    parser.add_argument("--input", help="use a local folder holding spawn_rules and entities instead of downloading")
    args = parser.parse_args()

    folders = load_folders(args, ["spawn_rules", "entities"])
    rules = read_spawn_rules(folders["spawn_rules"], load_blocks())
    sizes = read_sizes(folders["entities"])

    SPAWN_RULES_JSON.parent.mkdir(parents=True, exist_ok=True)
    SPAWN_RULES_JSON.write_text(spawn_rules_json(args.version, rules, sizes), encoding="utf-8")

    conditions = sum(len(rule["conditions"]) for rule in rules.values())
    print(f"Wrote {SPAWN_RULES_JSON.relative_to(ROOT)} ({len(rules)} mobs, {conditions} conditions, {len(sizes)} sizes)")


if __name__ == "__main__":
    main()