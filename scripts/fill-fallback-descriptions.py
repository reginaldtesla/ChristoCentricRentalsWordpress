"""Replace generic shop boilerplate with product-focused descriptions."""
from __future__ import annotations

import json
import re
from pathlib import Path

ROOT = Path(r"C:\laragon\www\ChristoCentricRentalsWordpress\wp-content\plugins\christocentric-rentals\data")
FALLBACK = ROOT / "shop-fallback.json"
INV = ROOT / "rentopian-inventory.json"

GENERIC = "from Christocentric Rentals"

LENS_RE = re.compile(
    r"(?P<fl>\d{2,3}(?:\s*-\s*\d{2,3})?)\s*mm|\b(?P<fl2>\d{2,3})\s*(?:mm)?\s*(?P<ap>f/?\s*[\d.]+)",
    re.I,
)
AP_RE = re.compile(r"f\s*/?\s*([\d.]+)", re.I)


def tidy_name(name: str) -> str:
    name = re.sub(r"\s+", " ", name).strip()
    words = name.split()
    n = len(words)
    if n >= 4 and n % 2 == 0 and words[: n // 2] == words[n // 2 :]:
        name = " ".join(words[: n // 2])
    return name


def cat_key(raw: str) -> str:
    raw = re.sub(r"\s+", " ", (raw or "").split("\n")[0].strip().lower())
    keys = [
        "cameras",
        "lenses",
        "camcorder",
        "audio gears",
        "gimbals",
        "drone",
        "continuous light",
        "strobe and flashes",
        "softbox",
        "cards",
        "cable",
        "transmitters",
        "tripods",
        "video switchers",
        "ad-ons",
        "tv",
        "accessories",
        "rental battery",
    ]
    for key in keys:
        if key in raw or raw.startswith(key):
            return key
    return "accessories"


def brand_hint(name: str) -> str:
    n = name.lower()
    brands = [
        "Canon",
        "Sony",
        "Nikon",
        "Godox",
        "DJI",
        "Sigma",
        "Tamron",
        "Hollyland",
        "Aputure",
        "Amaran",
        "Blackmagic",
        "Rode",
        "Shure",
        "Zoom",
        "Panasonic",
        "Lexar",
        "SanDisk",
        "Tilta",
        "Zhiyun",
        "Neewer",
        "Haida",
        "Epson",
        "Samsung",
        "Focusrite",
        "Boya",
        "Synco",
        "Osee",
        "Viltrox",
        "Miliboo",
        "Weifeng",
        "Tolifo",
        "Godox",
    ]
    for b in brands:
        if b.lower() in n:
            return b
    return ""


def describe(name: str, category: str) -> str:
    name = tidy_name(name)
    cat = cat_key(category)
    n = name.lower()
    brand = brand_hint(name)

    if "nd filter" in n or "filter" in n or "cpl" in n or "polarizer" in n:
        return (
            f"The {name} is a lens filter for exposure, glare, or motion control. "
            "Match the thread diameter (for example 67 mm, 77 mm, or 82 mm) to the front of the lens."
        )

    if "reflector" in n or "5 in 1" in n or "5-in-1" in n or "bounce" in n:
        return (
            f"The {name} is a collapsible reflector for bouncing or filling light on faces and products. "
            "Use silver or white for fill, gold for warmer skin, and the black side as a flag."
        )

    if "router" in n or "hotspot" in n or "wifi" in n or "starlink" in n:
        return (
            f"The {name} is a wireless network unit for on-set internet, live streams, and file transfer. "
            "Confirm SIM, coverage, and power for the location before relying on it for the shoot."
        )

    if cat == "lenses" or (
        "filter" not in n
        and re.search(r"\d{2,3}\s*-\s*\d{2,3}|\d{2,3}\s*mm|rf\d|ef\s*\d", n)
    ):
        ap = AP_RE.search(name)
        ap_txt = f" at f/{ap.group(1).replace('f', '').replace('/', '').strip()}" if ap else ""
        mount = "RF-mount" if " rf" in f" {n}" or n.startswith("rf") or "rf" in n[:18] else ""
        if "ef" in n and "rf" not in n:
            mount = "EF-mount"
        if "sony" in n or "gm" in n or "e-mount" in n:
            mount = "Sony E-mount"
        if "nikkor" in n or "nikon" in n:
            mount = "Nikon F-mount"
        bits = [f"The {name} is a {brand + ' ' if brand else ''}photographic lens{ap_txt}.".replace("  ", " ")]
        if mount:
            bits.append(f"It is built for {mount} cameras.")
        if re.search(r"70\s*-\s*200|70-200", n):
            bits.append("A telephoto zoom for portraits, events, and compressed backgrounds.")
        elif re.search(r"24\s*-\s*70|24-70", n):
            bits.append("A standard zoom for events, interviews, and general production.")
        elif re.search(r"16\s*-\s*35|15\s*-\s*35|14\s*-\s*24|16-35", n):
            bits.append("A wide zoom for interiors, groups, and establishing shots.")
        elif re.search(r"150\s*-\s*600|150-600", n):
            bits.append("A super-telephoto zoom for sport, wildlife, and distant action.")
        elif re.search(r"\b85\b", n):
            bits.append("A short telephoto prime suited to portraits and isolated subjects.")
        elif re.search(r"\b50\b", n):
            bits.append("A standard prime for interviews, portraits, and available-light work.")
        elif re.search(r"\b35\b", n):
            bits.append("A wide-standard prime for documentary, walk-and-talk, and environmental portraits.")
        elif re.search(r"\b24\b", n):
            bits.append("A wide prime for interiors and dramatic foregrounds.")
        else:
            bits.append("Match it to a compatible camera body and adapter if the mounts differ.")
        return " ".join(bits)

    if cat == "cameras" or re.search(r"\b(r5|r6|fx3|fx6|a7|5d|6d|80d|60d|bmpc|body)\b", n):
        bits = [f"The {name} is a {brand + ' ' if brand else ''}camera body for stills and video.".replace("  ", " ")]
        if "body" in n:
            bits.append("This listing is the body only—pair it with a lens, cards, and batteries.")
        if "adapter" in n:
            bits.append("It includes a mount adapter so EF or other glass can be used on this body.")
        if "r5" in n:
            bits.append("Canon RF-mount full-frame body used for high-resolution photo and detailed video.")
        elif "r6" in n:
            bits.append("Canon RF-mount full-frame body aimed at hybrid photo and video work.")
        elif "fx3" in n or "fx6" in n or "fx30" in n:
            bits.append("A Sony cinema-oriented body for run-and-gun and controlled video.")
        elif "a7" in n:
            bits.append("Sony full-frame mirrorless body; E-mount lenses attach directly.")
        elif "5d" in n or "6d" in n or "80d" in n or "60d" in n:
            bits.append("Canon DSLR with EF lens mount.")
        elif "blackmagic" in n or "bmpc" in n:
            bits.append("Cinema camera body; plan for media, V-mount or dummy power as required.")
        return " ".join(bits)

    if cat == "camcorder" or re.search(r"pxw|xa11|ux90|x1500|camcorder", n):
        return (
            f"The {name} is a {brand + ' ' if brand else ''}shoulder-style or handheld camcorder ".replace("  ", " ")
            + "for events, church, and long-form video. Built-in lens and XLR or mic options vary by model; confirm audio inputs for your shoot."
        )

    if cat == "gimbals" or "ronin" in n or "crane" in n or "gimbal" in n:
        return (
            f"The {name} is a motorized camera stabilizer for walking shots, reveals, and handheld moves. "
            "Balance the camera and lens on the gimbal before the shoot; extra batteries are recommended for long days."
        )

    if cat == "drone" or "mavic" in n or "mini 3" in n or "mini 4" in n or "mini 5" in n or n.strip() == "dji mini 5 pro":
        return (
            f"The {name} is a {brand + ' ' if brand else ''}drone for aerial establishing shots and overhead coverage. ".replace("  ", " ")
            + "Fly only where local rules allow; extra batteries and an ND set help in bright Ghana sun."
        )

    if cat == "continuous light" or any(x in n for x in ["amaran", "aputure", "tolifo", "neewer", "yidoblo", "tube light", "tl120", "tl60", "300c", "150c", "600d"]):
        return (
            f"The {name} is a continuous LED fixture for video and mixed lighting. "
            "Use it as key, fill, or background light; add a stand and modifier when you need control."
        )

    if cat == "strobe and flashes" or any(x in n for x in ["ad600", "ad200", "ad800", "speedlight", "v1", "v860", "v850", "x-pro", "x3", "x2t"]):
        return (
            f"The {name} is a flash or radio trigger for stills—portraits, events, and studio setups. "
            "Match the trigger to the camera brand (Canon, Sony, or Nikon) listed in the name."
        )

    if cat == "softbox" or "softbox" in n:
        size = re.search(r"(\d{2,3})\s*cm", n)
        size_txt = f"{size.group(1)} cm " if size else ""
        return (
            f"The {name} is a {size_txt}softbox that spreads and softens flash or LED output. "
            "Mount it on a compatible speedring and light, then feather the edge for portraits or interviews."
        )

    if cat == "audio gears" or any(x in n for x in ["mic", "rode", "shure", "zoom h", "hollyland lark", "boya", "synco", "focusrite", "walkie", "podmic", "sm7b"]):
        return (
            f"The {name} is {brand + ' ' if brand else ''}location or studio audio gear ".replace("  ", " ")
            + "for dialogue, podcasts, or live capture. Check whether you need extra cables, stands, or wireless receivers for your setup."
        )

    if cat == "transmitters" or "mars" in n or "pyro" in n or "cineview" in n:
        return (
            f"The {name} is a wireless video transmitter/receiver set for on-set monitoring. "
            "Use it to send camera output to a director’s monitor without a long HDMI run."
        )

    if cat == "video switchers" or "atem" in n or "gostream" in n:
        return (
            f"The {name} is a live video switcher for multicam events and streams. "
            "Plan HDMI or SDI inputs, a program output, and ISO recording if the model supports it."
        )

    if cat == "tripods" or "tripod" in n or "monopod" in n or re.search(r"\bc-?\s*stand\b", n) or "silver stand" in n:
        return (
            f"The {name} is a camera or lighting stand. "
            "Use it to lock off a shot or hold a fixture; check payload against your camera or light."
        )

    if cat == "cards" or "sd card" in n or "ssd" in n or "micro sd" in n:
        return (
            f"The {name} is recording media for cameras and recorders. "
            "Confirm the card type (SD, microSD, or SSD) and speed class matches the body you are hiring."
        )

    if cat == "cable" or "hdmi" in n or "sdi" in n or "type c" in n:
        length = re.search(r"(\d+)\s*m\b", n)
        length_txt = f"{length.group(1)} m " if length else ""
        return (
            f"The {name} is a {length_txt}signal cable for video or power interconnects. "
            "Use the correct HDMI, SDI, or USB-C ends listed in the product name."
        )

    if "battery" in n or "charger" in n or cat == "rental battery":
        return (
            f"The {name} is a spare battery or charger for the matching camera, light, or wireless kit. "
            "Confirm the chemistry and mount (LP-E6, NP-FZ100, V-mount, and so on) before adding it to a booking."
        )

    if cat == "tv" or re.search(r"\btv\b|monitor \(lg\)|smart tv", n):
        return (
            f"The {name} is a display for playback, confidence monitoring, or event screens. "
            "Bring the correct HDMI input and a stand or wall position as needed."
        )

    if "v-mount" in n or "vmount" in n or "v mount" in n:
        return (
            f"The {name} is a V-mount battery (and clamp/dummy where listed) for cinema cameras and high-draw lights. "
            "Check voltage and dummy-battery compatibility with your body."
        )

    if "clamp" in n or "magic arm" in n or "super clamp" in n or "gobo" in n:
        return (
            f"The {name} is a grip mounting piece for locking lights, flags, or monitors to pipe, stands, or set pieces. "
            "Confirm the jaw size and load before hanging a fixture."
        )

    if "sandbag" in n or "weight" in n:
        return (
            f"The {name} is a ballast weight for lighting and camera stands. "
            "Place it on the legs to keep a C-stand or boom from tipping when a fixture is offset."
        )

    if "apple box" in n or "applebox" in n:
        return (
            f"The {name} is a wooden apple box for raising talent, cameras, or practicals by a set height. "
            "Stack or use as a seat, step, or low camera platform."
        )

    if "case" in n or "bag" in n or "backpack" in n:
        return (
            f"The {name} is a protective case or bag for transporting camera, audio, or lighting kits. "
            "Check interior size against the bodies and lenses you plan to pack."
        )

    if "adapter" in n or "converter" in n or "dongle" in n:
        return (
            f"The {name} is a mount or signal adapter between camera, lens, or cable standards. "
            "Match the ends in the product name (EF-RF, HDMI, USB-C, and so on) to the bodies you are using."
        )

    if "light meter" in n or "sekonic" in n:
        return (
            f"The {name} is a handheld light meter for setting exposure on stills and cinema lighting. "
            "Use incident or spot readings as the model allows."
        )

    return (
        f"The {name} is a {brand + ' ' if brand else ''}production accessory: ".replace("  ", " ")
        + "grip, power, mounting, or a small kit item used with a camera or light. "
        + "Read the product name for size, mount, and what it attaches to."
    )


def is_generic(text: str) -> bool:
    t = text or ""
    return (
        GENERIC in t
        or "Add your pickup and return" in t
        or t.startswith("Rent the ")
        or "in this rental catalog" in t
        or "production accessory in this rental" in t
    )


def main() -> None:
    fallback = json.loads(FALLBACK.read_text(encoding="utf-8"))
    inv_by_key = {}
    if INV.is_file():
        for row in json.loads(INV.read_text(encoding="utf-8")):
            inv_by_key[row.get("key", "")] = row

    updated = 0
    for key, row in fallback.items():
        current = str(row.get("description") or "")
        name_guess = str(row.get("name") or key)
        wrong_lens = "filter" in name_guess.lower() and "photographic lens" in current
        if current.strip() and not is_generic(current) and not wrong_lens:
            continue
        inv = inv_by_key.get(key, {})
        name = str(row.get("name") or inv.get("name") or key)
        cat = str(inv.get("category") or "")
        text = describe(name, cat)
        row["description"] = text
        row["short_description"] = tidy_name(name)
        updated += 1

    FALLBACK.write_text(json.dumps(fallback, ensure_ascii=False, separators=(",", ":")), encoding="utf-8")
    print(f"updated={updated} total={len(fallback)}")
    # sample
    for key, row in fallback.items():
        if is_generic(row.get("description") or ""):
            continue
        if "r5 body" in key or key == "c stand":
            print(key, "=>", row["description"][:180])


if __name__ == "__main__":
    main()
