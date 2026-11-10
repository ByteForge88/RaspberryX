"""Writes resources/vanilla/command_enums.json from Mojang's bedrock-samples.

Usage: python tools/generate-command-enums.py <bedrock-samples folder>

The enums are the lists vanilla's commands suggest, like every item name for /give. Run it again
when a new Minecraft version comes out.
"""

import json
import pathlib
import sys

ENUMS = ["Item"]


def main() -> None:
    samples = pathlib.Path(sys.argv[1])
    data = json.loads((samples / "metadata/command_modules/mojang-commands.json").read_text(encoding="utf-8"))
    version = json.loads((samples / "version.json").read_text(encoding="utf-8"))["latest"]["version"]
    enums = {}

    for enum in data["command_enums"]:
        if enum["name"] in ENUMS:
            enums[enum["name"]] = [value["value"] for value in enum["values"]]

    out = pathlib.Path(__file__).resolve().parent.parent / "resources/vanilla/command_enums.json"
    out.write_text(json.dumps({"version": version, "enums": enums}, indent=2) + "\n", encoding="utf-8")
    print(f"wrote {out} ({', '.join(f'{name}: {len(values)}' for name, values in enums.items())})")


if __name__ == "__main__":
    main()