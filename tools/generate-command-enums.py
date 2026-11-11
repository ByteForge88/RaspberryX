#!/usr/bin/env python3
"""
Generates the lists vanilla's commands suggest, like every item name for /give, from Mojang's
official bedrock-samples repository.

Url: https://github.com/Mojang/bedrock-samples

file metadata/command_modules/mojang-commands.json.

Outputs:
  resources/vanilla/command_enums.json   the Item enum, in the game's order

Usage:
  python tools/generate-command-enums.py --version 1.26.60.5

  More advanced:
  python tools/generate-command-enums.py --input path/to/folder --version 1.26.60.5

Info:
  the folder holds mojang-commands.json
"""

import argparse
import json
import sys
import time
import urllib.error
import urllib.request
from pathlib import Path

SOURCE_URL = "https://raw.githubusercontent.com/Mojang/bedrock-samples/v{version}/metadata/command_modules/mojang-commands.json"

ROOT = Path(__file__).resolve().parent.parent

COMMAND_ENUMS_JSON = ROOT / "resources" / "vanilla" / "command_enums.json"

ENUMS = ["Item"]


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


def load_source(args):
    if args.input is not None:
        path = Path(args.input) / "mojang-commands.json"

        if not path.is_file():
            fail(f"{path} does not exist")

        return json.loads(path.read_text(encoding="utf-8-sig"))

    url = SOURCE_URL.format(version=args.version)
    print(f"Downloading {url}")

    return json.loads(download_text(url))


def read_enums(source):
    enums = {}

    for enum in source.get("command_enums", []):
        if enum["name"] in ENUMS:
            enums[enum["name"]] = [value["value"] for value in enum["values"]]

    missing = [name for name in ENUMS if name not in enums]

    if missing:
        fail(f"mojang-commands.json has no {', '.join(missing)} enum")

    return enums


def main():
    parser = argparse.ArgumentParser(description="Generate RaspberryX command enums from Mojang bedrock-samples")
    parser.add_argument("--version", required=True, help="bedrock-samples release tag without the v, e.g. 1.26.60.5")
    parser.add_argument("--input", help="use a local command_modules folder instead of downloading")
    args = parser.parse_args()

    enums = read_enums(load_source(args))

    COMMAND_ENUMS_JSON.parent.mkdir(parents=True, exist_ok=True)
    COMMAND_ENUMS_JSON.write_text(json.dumps({"version": args.version, "enums": enums}, indent=2) + "\n", encoding="utf-8")

    print(f"Wrote {COMMAND_ENUMS_JSON.relative_to(ROOT)} ({', '.join(f'{name}: {len(values)}' for name, values in enums.items())})")


if __name__ == "__main__":
    main()