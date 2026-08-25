"""Compare live shop product names with local Rentopian inventory."""
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
    name = re.sub(r"[^a-z0-9]+", " ", name)
    return re.sub(r"\s+", " ", name).strip()


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
    if not found:
        found = re.findall(
            r'<h3 class="product-card-title[^"]*"[^>]*>(.*?)</h3>',
            html,
            re.I | re.S,
        )
    if not found:
        found = re.findall(r'<img[^>]*class="product-card-image"[^>]*alt="([^"]+)"', html, re.I)
    titles = []
    for raw in found:
        text = re.sub(r"<[^>]+>", "", raw)
        text = re.sub(r"\s+", " ", text).strip()
        if text:
            titles.append(text)
    return titles


def main() -> None:
    live: list[str] = []
    page = 1
    while page <= 40:
        url = "https://christocentricrentals.com/shop/" if page == 1 else f"https://christocentricrentals.com/shop/page/{page}/"
        try:
            html = fetch(url)
        except Exception as exc:
            print(f"FETCH_FAIL {url} {exc}")
            break
        titles = titles_from_shop(html)
        print(f"page {page} titles={len(titles)}")
        if not titles:
            # last page or markup change
            if page == 1:
                Path("scripts/_shop-page1.html").write_text(html[:8000], encoding="utf-8")
            break
        live.extend(titles)
        if f"/shop/page/{page + 1}/" not in html and 'class="next page-numbers"' not in html:
            break
        page += 1
        time.sleep(0.4)

    live_norm = {normalize(t): t for t in live}
    inv_rental = [x for x in INV if str(x.get("type", "rental")).lower() == "rental"]
    missing = []
    for row in inv_rental:
        key = normalize(row["name"])
        if key not in live_norm:
            missing.append(row["name"])

    extra = []
    inv_keys = {normalize(x["name"]) for x in inv_rental}
    for key, title in live_norm.items():
        if key not in inv_keys:
            extra.append(title)

    print(f"live={len(live)} unique_live={len(live_norm)}")
    print(f"inventory_rental={len(inv_rental)} sale={sum(1 for x in INV if x.get('type')=='sale')}")
    print(f"missing={len(missing)}")
    for name in missing:
        print("MISSING", name)
    print(f"on_shop_not_in_pdf={len(extra)}")
    for name in extra[:40]:
        print("EXTRA", name)


if __name__ == "__main__":
    main()
