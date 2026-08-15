<?php
/**
 * Build products/image-search-links.csv + .html with search URLs per product.
 * Run: php scripts/build-image-search-links.php
 */

declare(strict_types=1);

$wpRoot = dirname(__DIR__);
$csvIn = $wpRoot . '/products/product-list.csv';
$csvOut = $wpRoot . '/products/image-search-links.csv';
$htmlOut = $wpRoot . '/products/image-search-links.html';

if (! is_file($csvIn)) {
    fwrite(STDERR, "Missing {$csvIn}\n");
    exit(1);
}

$in = fopen($csvIn, 'r');
$header = fgetcsv($in, 0, ',', '"', '\\');
$rows = [];

while (($row = fgetcsv($in, 0, ',', '"', '\\')) !== false) {
    if (! $row || ($row[0] ?? '') === '' || str_starts_with((string) ($row[2] ?? ''), '---')) {
        continue;
    }
    $id = $row[0] ?? '';
    $sku = $row[1] ?? '';
    $name = $row[2] ?? '';
    $slug = $row[3] ?? '';
    $category = $row[4] ?? '';
    if ($name === '' || ! ctype_digit((string) $id)) {
        continue;
    }
    $rows[] = compact('id', 'sku', 'name', 'slug', 'category');
}
fclose($in);

$out = fopen($csvOut, 'w');
fputcsv($out, [
    'id', 'sku', 'name', 'slug', 'category', 'folder',
    'bh_search', 'adorama_search', 'google_images', 'brand_site_search',
], ',', '"', '\\');

$html = [];
$html[] = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
$html[] = '<title>Christocentric — Product image search links</title>';
$html[] = '<style>
body{font-family:system-ui,sans-serif;max-width:1100px;margin:2rem auto;padding:0 1rem;color:#111}
h1{font-size:1.4rem} h2{margin-top:2rem;border-bottom:1px solid #ddd;padding-bottom:.4rem}
table{width:100%;border-collapse:collapse;font-size:.9rem;margin-bottom:1.5rem}
td,th{border:1px solid #e5e7eb;padding:.45rem .6rem;vertical-align:top}
th{background:#f3f4f6;text-align:left}
a{color:#1e73be} .muted{color:#6b7280;font-size:.8rem}
.note{background:#fff8e6;border:1px solid #f5d78e;padding:.75rem 1rem;border-radius:8px;margin:1rem 0}
</style></head><body>';
$html[] = '<h1>Product image search links</h1>';
$html[] = '<div class="note"><strong>Note:</strong> These are search pages (B&amp;H, Adorama, Google Images, brand site) — not direct hotlinks to copyrighted files. Open a link, download the official product photo, save as <code>products/{category-slug}/{product-slug}.png</code>.</div>';

$byCat = [];
foreach ($rows as $r) {
    $cat = $r['category'] !== '' ? $r['category'] : 'Uncategorized';
    $byCat[$cat][] = $r;
}
ksort($byCat);

foreach ($byCat as $cat => $items) {
    $folder = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $cat) ?? 'uncategorized');
    $folder = trim($folder, '-');
    $html[] = '<h2>' . htmlspecialchars($cat) . ' <span class="muted">→ products/' . htmlspecialchars($folder) . '/</span></h2>';
    $html[] = '<table><thead><tr><th>Product</th><th>Save as</th><th>Links</th></tr></thead><tbody>';

    foreach ($items as $r) {
        $q = rawurlencode($r['name']);
        $bh = 'https://www.bhphotovideo.com/c/search?Ntt=' . $q;
        $adorama = 'https://www.adorama.com/l/?searchinfo=' . $q;
        $google = 'https://www.google.com/search?tbm=isch&q=' . $q . rawurlencode(' product transparent PNG');
        $brand = ccr_brand_search_url($r['name'], $q);

        fputcsv($out, [
            $r['id'], $r['sku'], $r['name'], $r['slug'], $r['category'], $folder,
            $bh, $adorama, $google, $brand,
        ], ',', '"', '\\');

        $html[] = '<tr>';
        $html[] = '<td><strong>' . htmlspecialchars($r['name']) . '</strong><div class="muted">' . htmlspecialchars($r['sku']) . '</div></td>';
        $html[] = '<td><code>' . htmlspecialchars($r['slug']) . '.png</code></td>';
        $html[] = '<td>';
        $html[] = '<a href="' . htmlspecialchars($bh) . '" target="_blank" rel="noopener">B&amp;H</a> · ';
        $html[] = '<a href="' . htmlspecialchars($adorama) . '" target="_blank" rel="noopener">Adorama</a> · ';
        $html[] = '<a href="' . htmlspecialchars($google) . '" target="_blank" rel="noopener">Google Images</a> · ';
        $html[] = '<a href="' . htmlspecialchars($brand) . '" target="_blank" rel="noopener">Brand search</a>';
        $html[] = '</td></tr>';
    }
    $html[] = '</tbody></table>';
}

$html[] = '</body></html>';
fclose($out);
file_put_contents($htmlOut, implode("\n", $html));

echo 'Wrote ' . count($rows) . " links\n";
echo "CSV:  {$csvOut}\n";
echo "HTML: {$htmlOut}\n";
echo "Open the HTML file in your browser and click through.\n";

function ccr_brand_search_url(string $name, string $encodedQuery): string
{
    $n = strtolower($name);
    $map = [
        'canon' => 'https://www.usa.canon.com/search?q=',
        'sony' => 'https://electronics.sony.com/search?query=',
        'nikon' => 'https://www.nikonusa.com/search?q=',
        'sigma' => 'https://www.sigma-global.com/en/?s=',
        'dji' => 'https://www.dji.com/search?q=',
        'godox' => 'https://www.godox.com/?s=',
        'aputure' => 'https://www.aputure.com/?s=',
        'amaran' => 'https://www.amaran.com/?s=',
        'hollyland' => 'https://www.hollyland.com/?s=',
        'rode' => 'https://rode.com/en/search?q=',
        'rhode' => 'https://rode.com/en/search?q=',
        'zoom' => 'https://zoomcorp.com/?s=',
        'blackmagic' => 'https://www.blackmagicdesign.com/search?q=',
        'atem' => 'https://www.blackmagicdesign.com/search?q=',
        'zhiyun' => 'https://www.zhiyun-tech.com/?s=',
        'shure' => 'https://www.shure.com/en-US/search?q=',
        'epson' => 'https://epson.com/search?q=',
        'tamron' => 'https://www.tamron-usa.com/search?q=',
        'lexar' => 'https://www.lexar.com/?s=',
        'neewer' => 'https://neewer.com/search?q=',
        'feelworld' => 'https://www.feelworld.tv/?s=',
    ];

    foreach ($map as $needle => $base) {
        if (str_contains($n, $needle)) {
            return $base . $encodedQuery;
        }
    }

    return 'https://www.google.com/search?q=' . $encodedQuery;
}
