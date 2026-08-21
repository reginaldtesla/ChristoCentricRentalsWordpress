"""Parse Rentopian Inventory_Export PDF into shop-fallback rows."""
from __future__ import annotations

import json
import re
from pathlib import Path

from pypdf import PdfReader

PDF = Path(r"C:\Users\Reginald Tesla\Downloads\Documents\Inventory_Export_1786796457.pdf")
OUT = Path(__file__).resolve().parents[1] / "wp-content/plugins/christocentric-rentals/data/rentopian-inventory.json"


def normalize_name(name: str) -> str:
    name = re.sub(r"<[^>]+>", "", name)
    name = name.lower()
    name = re.sub(r"[^a-z0-9]+", " ", name)
    return re.sub(r"\s+", " ", name).strip()


def money(value: str) -> float:
    return float(value.replace(",", ""))


def collapse_duplicate_name(name: str) -> str:
    name = re.sub(r"\s+", " ", name).strip()
    words = name.split()
    n = len(words)
    if n >= 2 and n % 2 == 0:
        half = n // 2
        if words[:half] == words[half:]:
            return " ".join(words[:half])
    return name


def parse(text: str) -> list[dict]:
    chunks = re.split(r"(?m)(?=^\d{6}\s)", text)
    rows = []
    for chunk in chunks:
        m = re.match(r"^(\d{6})\s+", chunk)
        if not m:
            continue
        remote_id = m.group(1)
        if "Main" not in chunk or "Location" not in chunk:
            continue
        kind = "Rental"
        if re.search(r"\bSale\b", chunk) and not re.search(r"\bRental\b", chunk):
            kind = "Sale"
        prices = re.findall(r"₵([\d,]+\.\d{2})", chunk)
        if len(prices) < 4:
            continue
        qty_m = re.search(rf"{kind}\s+(\d+)", chunk)
        qty = int(qty_m.group(1)) if qty_m else 1
        rental = money(prices[3])
        name_m = re.search(
            r"Main\s+Location\s+([\s\S]+?)\s+(Yes|No)\s+",
            chunk,
        )
        if not name_m:
            continue
        raw_name = collapse_duplicate_name(name_m.group(1))
        raw_name = re.sub(r"\s+", " ", raw_name).strip()
        if not raw_name:
            continue
        cat_m = re.search(
            r"(Accessories|Cameras|Lenses|Audio gears|Camcorder|Continuous light|Drone|Gimbals|"
            r"Cards|Cable|Softbox|Strobe and flashes|Transmitters|Tripods|Video switchers|"
            r"Ad-ons|Tv|Rental Battery|Selling Products)[^\n]*",
            chunk,
            re.I,
        )
        rows.append(
            {
                "id": remote_id,
                "name": raw_name,
                "key": normalize_name(raw_name),
                "quantity": max(0, qty),
                "price": rental,
                "type": kind.lower(),
                "category": cat_m.group(0).strip() if cat_m else "",
            }
        )
    return rows


def main() -> None:
    reader = PdfReader(str(PDF))
    text = "\n".join((page.extract_text() or "") for page in reader.pages)
    rows = parse(text)
    priced = sum(1 for r in rows if r["price"] > 0)
    OUT.parent.mkdir(parents=True, exist_ok=True)
    OUT.write_text(json.dumps(rows, ensure_ascii=False, indent=2), encoding="utf-8")
    print(f"parsed={len(rows)} priced={priced} wrote={OUT}")
    for sample in rows:
        if "r5" in sample["key"] and "body" in sample["key"]:
            print("sample", sample)
            break


if __name__ == "__main__":
    main()
