<?php
/**
 * Clean product galleries: unique file hashes only.
 * Removes duplicate cutouts; clears gallery when nothing distinct remains.
 *
 * Run: php scripts/cleanup-product-galleries.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

require dirname(__DIR__) . '/wp-load.php';

echo "=== Cleanup product galleries ===\n";

$cleared = 0;
$trimmed = 0;
$unchanged = 0;

foreach (wc_get_products(['limit' => -1, 'status' => 'publish']) as $product) {
    $productId = $product->get_id();
    $featuredId = (int) $product->get_image_id();
    $galleryIds = array_values(array_filter(array_map('intval', $product->get_gallery_image_ids())));

    if ($galleryIds === []) {
        $unchanged++;
        continue;
    }

    $seen = [];
    if ($featuredId > 0) {
        $ff = get_attached_file($featuredId);
        if (is_file((string) $ff)) {
            $seen[md5_file($ff)] = true;
        }
    }

    $unique = [];
    foreach ($galleryIds as $gid) {
        if ($gid === $featuredId) {
            continue;
        }
        $file = get_attached_file($gid);
        if (! is_file((string) $file)) {
            continue;
        }
        $hash = md5_file($file);
        if (isset($seen[$hash])) {
            continue;
        }
        // Skip cutouts that are just the same product stem (not a new angle).
        $base = strtolower(pathinfo((string) $file, PATHINFO_FILENAME));
        if (str_ends_with($base, '-cutout') || str_contains($base, '-cutout-')) {
            continue;
        }
        $seen[$hash] = true;
        $unique[] = $gid;
    }

    if ($unique === []) {
        delete_post_meta($productId, '_product_image_gallery');
        $cleared++;
        echo "Cleared (no distinct extras): {$product->get_name()}\n";
        continue;
    }

    if ($unique === $galleryIds) {
        $unchanged++;
        continue;
    }

    update_post_meta($productId, '_product_image_gallery', implode(',', $unique));
    $trimmed++;
    echo "Trimmed: {$product->get_name()} → " . count($unique) . " related\n";
}

echo "\nCleared: {$cleared}\nTrimmed: {$trimmed}\nUnchanged: {$unchanged}\nDone.\n";
