import urllib.request
from pathlib import Path
req = urllib.request.Request(
    "https://christocentricrentals.com/shop/",
    headers={"User-Agent": "Mozilla/5.0 (compatible; CCR-catalog-check/1.0)"},
)
with urllib.request.urlopen(req, timeout=40) as resp:
    html = resp.read().decode("utf-8", "ignore")
Path("scripts/_shop-page1.html").write_text(html, encoding="utf-8")
print("bytes", len(html))
print(html[:1500])
