"""Convert every product photo to lossless PNG at full resolution."""
from __future__ import annotations

from pathlib import Path

from PIL import Image, ImageSequence

ROOT = Path(r"C:\laragon\www\ChristoCentricRentalsWordpress")
TARGETS = [
    ROOT / "Products Images",
    ROOT / "wp-content/plugins/christocentric-rentals/data/product-images",
]
IMAGE_EXT = {".jpg", ".jpeg", ".png", ".webp", ".gif", ".bmp", ".tif", ".tiff", ".avif"}
Image.MAX_IMAGE_PIXELS = None


def to_png_mode(im: Image.Image) -> Image.Image:
    if getattr(im, "n_frames", 1) > 1:
        im = ImageSequence.Iterator(im).__next__()
    if im.mode in ("RGBA", "RGB"):
        return im
    if im.mode in ("LA", "P"):
        return im.convert("RGBA")
    if im.mode == "CMYK":
        return im.convert("RGB")
    if "A" in im.mode:
        return im.convert("RGBA")
    return im.convert("RGB")


def convert_file(path: Path) -> str:
    dest = path.with_suffix(".png")
    with Image.open(path) as im:
        rgb = to_png_mode(im)
        rgb.save(
            dest,
            format="PNG",
            compress_level=1,
            optimize=False,
        )
    if dest.resolve() != path.resolve() and path.exists():
        path.unlink()
    return dest.name


def main() -> None:
    converted = 0
    skipped = 0
    failed = 0
    for root in TARGETS:
        if not root.is_dir():
            continue
        for path in root.rglob("*"):
            if not path.is_file() or path.suffix.lower() not in IMAGE_EXT:
                continue
            if path.suffix.lower() == ".png":
                skipped += 1
                continue
            try:
                convert_file(path)
                converted += 1
                if converted % 50 == 0:
                    print(f"converted={converted}")
            except Exception as exc:  # noqa: BLE001
                failed += 1
                print(f"FAIL {path}: {exc}")
    print(f"done converted={converted} already_png={skipped} failed={failed}")


if __name__ == "__main__":
    main()
