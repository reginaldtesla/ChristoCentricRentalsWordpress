"""Fuzzy-compare live shop titles vs Rentopian PDF inventory."""
from __future__ import annotations

import json
import re
import time
import urllib.request
from pathlib import Path

ROOT = Path(r"C:\laragon\www\ChristoCentricRentalsWordpress")
INV = json.loads((ROOT / "wp-content/plugins/christocentric-rentals/data/rentopian-inventory.json").read_text(encoding="utf-8"))
UA = {"User-Agent": "Mozilla/5.0 (compatible; CCR-catalog-check/1.0)"}


def normalize(name: str) -> str:
    name = name.lower()
    name = re.sub(r"<[^>]+>", "", name)
    name = name.replace("fibre", " ").replace("fiber", " ")
    name = re.sub(r"[^a-z0-9]+", " ", name)
    return re.sub(r"\s+", " ", name).strip()


def collapse(name: str) -> str:
    words = normalize(name).split()
    n = len(words)
    if n >= 2 and n % 2 == 0 and words[: n // 2] == words[n // 2 :]:
        return " ".join(words[: n // 2])
    return " ".join(words)


def fetch(url: str) -> str:
    req = urllib.request.Request(url, headers=UA)
    with urllib.request.urlopen(req, timeout=40) as resp:
        return resp.read().decode("utf-8", "ignore")


def titles_from_shop(html: str) -> list[str]:
    found = re.findall(
        r'class="product-card-title[^"]*"[^>]*>\s*<a[^>]*>(.*?)</a>',
        html,
        re.I | re.S,
    )
    titles = []
    for raw in found:
        text = re.sub(r"<[^>]+>", "", raw)
        text = re.sub(r"\s+", " ", text).strip()
        if text:
            titles.append(text)
    return titles


def best_match(needle: str, hay: dict[str, str]) -> str | None:
    n = collapse(needle)
    if n in hay:
        return hay[n]
    for key, title in hay.items():
        if n == key or n.startswith(key + " ") or key.startswith(n + " "):
            return title
        if n in key or key in n:
            if min(len(n), len(key)) >= 10:
                return title
    return None


def main() -> None:
    live: list[str] = []
    page = 1
    while page <= 40:
        url = "https://christocentricrentals.com/shop/" if page == 1 else f"https://christocentricrentals.com/shop/page/{page}/"
        html = fetch(url)
        titles = titles_from_shop(html)
        print(f"page {page} titles={len(titles)}")
        if not titles:
            break
        live.extend(titles)
        if f"/shop/page/{page + 1}/" not in html:
            break
        page += 1
        time.sleep(0.25)

    live_map = {collapse(t): t for t in live}
    inv_rental = [x for x in INV if str(x.get("type", "rental")).lower() == "rental"]
    missing = []
    matched = {}
    for row in inv_rental:
        hit = best_match(row["name"], live_map)
        if hit is None:
            missing.append(row["name"])
        else:
            matched[collapse(row["name"])] = hit

    used = {collapse(v) for v in matched.values()}
    extra = [t for t in dict.fromkeys(live) if collapse(t) not in used and collapse(t) not in {collapse(m) for m in missing}]

    print(f"live={len(live)} unique_live={len(set(map(collapse, live)))}")
    print(f"inventory_rental={len(inv_rental)}")
    print(f"truly_missing={len(missing)}")
    for name in missing:
        print("MISSING", name)
    print(f"on_shop_not_in_pdf={len(extra)}")
    for name in extra:
        print("EXTRA", name)


if __name__ == "__main__":
    main()
