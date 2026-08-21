<?php
/**
 * Build plugin fallback catalog (rates + descriptions) from this WordPress shop.
 * Run: php scripts/export-shop-fallback.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

require dirname(__DIR__) . '/wp-load.php';

if (! class_exists('WooCommerce')) {
    fwrite(STDERR, "WooCommerce required.\n");
    exit(1);
}

$normalize = static function (string $name): string {
    $name = strtolower(wp_strip_all_tags($name));
    $name = preg_replace('/[^a-z0-9]+/', ' ', $name) ?? $name;

    return trim((string) preg_replace('/\s+/', ' ', $name));
};

$ids = get_posts([
    'post_type' => 'product',
    'post_status' => ['publish', 'draft', 'pending', 'private', 'trash'],
    'posts_per_page' => -1,
    'fields' => 'ids',
    'no_found_rows' => true,
]);

$best = [];
foreach ($ids as $id) {
    $id = (int) $id;
    $product = wc_get_product($id);
    if (! $product instanceof WC_Product) {
        continue;
    }
    $name = $product->get_name();
    $key = $normalize($name);
    if ($key === '') {
        continue;
    }
    $daily = (float) $product->get_meta('_ccr_price_per_day');
    if ($daily <= 0) {
        $daily = (float) $product->get_regular_price('edit');
    }
    $sale = (float) $product->get_meta('_ccr_sale_price_per_day');
    $desc = trim((string) $product->get_description('edit'));
    $short = trim((string) $product->get_short_description('edit'));
    $score = ($daily > 0 ? 10000 : 0) + min(5000, strlen($desc)) + min(500, strlen($short));
    if ($score <= 0) {
        continue;
    }
    $prev = $best[$key]['score'] ?? -1;
    if ($score <= $prev) {
        continue;
    }
    $best[$key] = [
        'score' => $score,
        'row' => [
            'name' => $name,
            'price' => $daily,
            'sale' => $sale > 0 ? $sale : null,
            'description' => $desc,
            'short_description' => $short,
        ],
    ];
}

$out = [];
foreach ($best as $key => $item) {
    unset($item['row']['score']);
    $out[$key] = $item['row'];
}

$dir = dirname(__DIR__) . '/wp-content/plugins/christocentric-rentals/data';
if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
    fwrite(STDERR, "Could not create data directory.\n");
    exit(1);
}

$path = $dir . '/shop-fallback.json';
$json = wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (! is_string($json) || file_put_contents($path, $json) === false) {
    fwrite(STDERR, "Could not write fallback file.\n");
    exit(1);
}

$priced = 0;
foreach ($out as $row) {
    if ((float) $row['price'] > 0) {
        $priced++;
    }
}

echo 'Wrote ' . count($out) . " products ({$priced} with prices) to {$path}\n";
echo 'Size ' . round(strlen($json) / 1024, 1) . " KB\n";
