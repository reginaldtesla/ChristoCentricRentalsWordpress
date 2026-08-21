"""Create one folder per inventory product with a description text file."""
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(r"C:\laragon\www\ChristoCentricRentalsWordpress")
OUT = ROOT / "Products Images"
INV = ROOT / "wp-content/plugins/christocentric-rentals/data/rentopian-inventory.json"
FALLBACK = ROOT / "wp-content/plugins/christocentric-rentals/data/shop-fallback.json"

INVALID = re.compile(r'[<>:"/\\|?*\x00-\x1f]')


def safe_folder_name(name: str) -> str:
    name = re.sub(r"\s+", " ", (name or "").strip())
    name = INVALID.sub(" ", name)
    name = re.sub(r"\s+", " ", name).strip(" .")
    return name[:120] or "unnamed-product"


def main() -> None:
    inventory = json.loads(INV.read_text(encoding="utf-8"))
    fallback = json.loads(FALLBACK.read_text(encoding="utf-8"))
    OUT.mkdir(parents=True, exist_ok=True)

    used: dict[str, int] = {}
    created = 0

    for row in inventory:
        name = str(row.get("name") or row.get("key") or "unnamed").strip()
        key = str(row.get("key") or "").strip().lower()
        pack = fallback.get(key) or {}
        folder_base = safe_folder_name(name)
        n = used.get(folder_base, 0)
        used[folder_base] = n + 1
        folder_name = folder_base if n == 0 else f"{folder_base} ({n + 1})"
        folder = OUT / folder_name
        folder.mkdir(parents=True, exist_ok=True)

        description = str(pack.get("description") or "").strip()
        short = str(pack.get("short_description") or "").strip()
        lines = [
            f"Product: {name}",
            f"Rentopian ID: {row.get('id', '')}",
            f"Category: {row.get('category', '')}",
            f"Type: {row.get('type', '')}",
            f"Quantity: {row.get('quantity', '')}",
            f"Daily rate (GHS): {row.get('price', '')}",
            "",
            "Description:",
            description if description else "(No description on file — drop product photos in this folder.)",
        ]
        if short and short != name:
            lines.extend(["", f"Short description: {short}"])
        lines.extend(
            [
                "",
                "Photos:",
                "Add clear product photos to this folder (JPG or PNG preferred).",
                "Name files like: front.jpg, side.jpg, kit.jpg",
            ]
        )

        (folder / "description.txt").write_text("\n".join(lines) + "\n", encoding="utf-8")
        created += 1

    print(f"folders={created} path={OUT}")


if __name__ == "__main__":
    main()
