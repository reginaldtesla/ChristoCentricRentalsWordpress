"""Copy cloned product photos into Products Images folders, then isolate imaged folders."""
from __future__ import annotations

import hashlib
import json
import re
import shutil
from collections import defaultdict
from pathlib import Path

ROOT = Path(r"C:\laragon\www\ChristoCentricRentalsWordpress")
DEST = ROOT / "Products Images"
PACK = ROOT / "wp-content/plugins/christocentric-rentals/data/product-images"
MIG = ROOT / "migration/images/storage/products"
INV = ROOT / "wp-content/plugins/christocentric-rentals/data/rentopian-inventory.json"

IMAGE_EXT = {".jpg", ".jpeg", ".png", ".webp", ".gif", ".avif", ".bmp"}
STOP = {
    "and", "the", "for", "with", "only", "body", "kit", "pro", "new", "old",
    "free", "added", "accessory", "stand", "cable", "cables", "battery",
    "charger", "monitor", "tripod", "light", "trigger", "adapter", "mm",
    "images", "storage", "products", "img", "photo", "image",
}


def normalize(text: str) -> str:
    text = text.lower()
    text = re.sub(r"[^a-z0-9]+", " ", text)
    return re.sub(r"\s+", " ", text).strip()


def tokens(text: str) -> list[str]:
    parts = normalize(text).split()
    out: list[str] = []
    for i, part in enumerate(parts):
        if len(part) >= 2:
            out.append(part)
        if len(part) == 1 and i + 1 < len(parts) and len(parts[i + 1]) >= 2:
            out.append(part + parts[i + 1])
    return list(dict.fromkeys(out))


def overlap(want: list[str], have: list[str]) -> int:
    have_set = set(have)
    return sum(1 for t in want if t in have_set)


def distinctive(toks: list[str]) -> list[str]:
    return [t for t in toks if t not in STOP]


def file_hash(path: Path) -> str:
    h = hashlib.md5()
    with path.open("rb") as fh:
        for chunk in iter(lambda: fh.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def collect_old_images() -> list[Path]:
    found: list[Path] = []
    for base in (PACK, MIG):
        if not base.is_dir():
            continue
        found.extend(p for p in base.rglob("*") if p.is_file() and p.suffix.lower() in IMAGE_EXT)
    return found


def unique_images(paths: list[Path]) -> list[Path]:
    by_hash: dict[str, Path] = {}
    for path in paths:
        digest = file_hash(path)
        existing = by_hash.get(digest)
        if existing is None or len(path.name) > len(existing.name):
            by_hash[digest] = path
    return list(by_hash.values())


def product_folders() -> list[Path]:
    skip = {"00000"}
    return [p for p in DEST.iterdir() if p.is_dir() and p.name not in skip]


def best_folder(stem: str, folders: list[tuple[Path, list[str], list[str]]]) -> Path | None:
    have = tokens(stem.replace("-", " ").replace("_", " "))
    if not have:
        return None
    best: tuple[int, int, Path] | None = None
    for folder, want, distinct in folders:
        score = overlap(want, have)
        dscore = overlap(distinct, have) if distinct else 0
        need = 1 if not distinct else max(1, (len(distinct) + 1) // 2)
        if score < need or dscore < 1:
            continue
        ranked = (dscore, score, folder)
        if best is None or (ranked[0], ranked[1]) > (best[0], best[1]):
            best = ranked
    return None if best is None else best[2]


def copy_into(folder: Path, src: Path, used_names: dict[str, int]) -> Path:
    ext = src.suffix.lower()
    stem = re.sub(r"[^a-zA-Z0-9._-]+", "-", src.stem).strip("-") or "photo"
    name = f"{stem}{ext}"
    n = used_names.get(name, 0)
    if n:
        name = f"{stem}-{n + 1}{ext}"
    used_names[name if n == 0 else f"{stem}{ext}"] = n + 1
    dest = folder / name
    while dest.exists():
        n += 1
        dest = folder / f"{stem}-{n}{ext}"
    shutil.copy2(src, dest)
    return dest


def merge_similar_folders(folders: list[Path]) -> dict[str, str]:
    """Merge folders whose normalized names are the same or one contains the other strongly."""
    groups: dict[str, list[Path]] = defaultdict(list)
    for folder in folders:
        key = normalize(folder.name)
        groups[key].append(folder)

    merged: dict[str, str] = {}
    for key, group in groups.items():
        if len(group) < 2:
            continue
        keep = sorted(group, key=lambda p: (len(p.name), p.name))[0]
        for extra in group[1:]:
            for item in extra.iterdir():
                if item.name == "description.txt" and (keep / "description.txt").exists():
                    extra_txt = item.read_text(encoding="utf-8", errors="ignore")
                    keep_txt = (keep / "description.txt").read_text(encoding="utf-8", errors="ignore")
                    if extra_txt not in keep_txt:
                        with (keep / "description.txt").open("a", encoding="utf-8") as fh:
                            fh.write("\n\n--- also listed as ---\n")
                            fh.write(extra_txt)
                    continue
                dest = keep / item.name
                if dest.exists():
                    dest = keep / f"{item.stem}-merged{item.suffix}"
                shutil.move(str(item), str(dest))
            extra.rmdir()
            merged[extra.name] = keep.name
    return merged


def has_images(folder: Path) -> bool:
    return any(p.is_file() and p.suffix.lower() in IMAGE_EXT for p in folder.iterdir())


def main() -> None:
    DEST.mkdir(parents=True, exist_ok=True)
    folders = product_folders()
    folder_meta = []
    for folder in folders:
        want = tokens(folder.name)
        folder_meta.append((folder, want, distinctive(want)))

    old = collect_old_images()
    unique = unique_images(old)
    placed = 0
    unmatched: list[str] = []
    used_names: dict[str, dict[str, int]] = defaultdict(dict)

    for src in unique:
        folder = best_folder(src.stem, folder_meta)
        if folder is None:
            unmatched.append(src.name)
            continue
        copy_into(folder, src, used_names[str(folder)])
        placed += 1

    remaining = product_folders()
    merged = merge_similar_folders(remaining)

    dest_00000 = DEST / "00000"
    dest_00000.mkdir(parents=True, exist_ok=True)
    moved = 0
    empty = 0
    for folder in product_folders():
        if has_images(folder):
            target = dest_00000 / folder.name
            if target.exists():
                for item in folder.iterdir():
                    dest = target / item.name
                    if dest.exists():
                        dest = target / f"{item.stem}-extra{item.suffix}"
                    shutil.move(str(item), str(dest))
                folder.rmdir()
            else:
                shutil.move(str(folder), str(target))
            moved += 1
        else:
            empty += 1

    print(f"old_files={len(old)}")
    print(f"unique_after_merge={len(unique)}")
    print(f"copied_into_folders={placed}")
    print(f"unmatched={len(unmatched)}")
    print(f"similar_folders_merged={len(merged)}")
    print(f"moved_into_00000={moved}")
    print(f"left_without_photos={empty}")
    if unmatched[:15]:
        print("unmatched_sample=" + " | ".join(unmatched[:15]))
    if merged:
        print("merged_folders=" + json.dumps(merged, ensure_ascii=False))


if __name__ == "__main__":
    main()
