<?php
/**
 * Attach featured (+ optional gallery) images to existing WooCommerce products.
 * Sources: migration/images/storage/products/{sku}.* and uploads/christocentric/
 *
 * Run: php scripts/attach-product-images.php
 *      php scripts/attach-product-images.php --force   (replace existing thumbs)
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

$wpRoot = dirname(__DIR__);
require_once $wpRoot . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

if (! class_exists('WooCommerce')) {
    fwrite(STDERR, "WooCommerce is not active.\n");
    exit(1);
}

$force = in_array('--force', $argv, true);
$migrationProducts = $wpRoot . '/migration/images/storage/products';
$migrationStorage = $wpRoot . '/migration/images/storage';
$uploadsChristo = $wpRoot . '/wp-content/uploads/christocentric';
$productsFolder = $wpRoot . '/products';

echo "=== Attach product images ===\n";
echo $force ? "Mode: force (replace existing)\n" : "Mode: only missing thumbnails\n";

$csvFile = $wpRoot . '/migration/woocommerce-products.csv';
$rows = [];

if (is_file($csvFile)) {
    $handle = fopen($csvFile, 'r');
    $header = fgetcsv($handle, 0, ',', '"', '\\');
    $col = array_flip($header ?: []);
    while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        if (count($row) < count($header)) {
            continue;
        }
        $sku = $row[$col['SKU']] ?? '';
        if ($sku === '') {
            continue;
        }
        $rows[] = [
            'sku' => $sku,
            'slug' => $row[$col['Meta: _ccr_laravel_slug']] ?? $sku,
            'image' => $row[$col['Images']] ?? '',
        ];
    }
    fclose($handle);
}

$attached = 0;
$gallerySet = 0;
$skippedOk = 0;
$missing = 0;
$notFound = 0;

foreach ($rows as $info) {
    $productId = wc_get_product_id_by_sku($info['sku']);
    if (! $productId) {
        // Try slug match
        $bySlug = get_page_by_path($info['slug'], OBJECT, 'product');
        if ($bySlug instanceof WP_Post) {
            $productId = (int) $bySlug->ID;
        }
    }

    if (! $productId) {
        $notFound++;
        continue;
    }

    $hasThumb = (int) get_post_thumbnail_id($productId) > 0;
    if ($hasThumb && ! $force) {
        $skippedOk++;
        continue;
    }

    $source = ccr_find_product_image_file(
        $info['sku'],
        $info['slug'],
        $info['image'],
        $migrationProducts,
        $migrationStorage,
        $uploadsChristo,
        $productsFolder
    );

    if ($source === null) {
        echo "Missing image: {$info['sku']}\n";
        $missing++;
        continue;
    }

    $attachmentId = ccr_sideload_image($source, $productId);
    if (! $attachmentId) {
        echo "Failed attach: {$info['sku']}\n";
        $missing++;
        continue;
    }

    set_post_thumbnail($productId, $attachmentId);
    $attached++;
    echo "Featured: {$info['sku']} ← " . basename($source) . "\n";

    $galleryIds = ccr_find_gallery_images($info['sku'], $info['slug'], $migrationProducts, $migrationStorage, $productsFolder, $productId);
    if ($galleryIds !== []) {
        update_post_meta($productId, '_product_image_gallery', implode(',', $galleryIds));
        $gallerySet++;
        echo "  Gallery: " . count($galleryIds) . " extra image(s)\n";
    }
}

echo "\nAttached featured: {$attached}\n";
echo "Galleries set: {$gallerySet}\n";
echo "Already had image (skipped): {$skippedOk}\n";
echo "Image file missing: {$missing}\n";
echo "Product not in WooCommerce: {$notFound}\n";
echo "Done.\n";

/**
 * @return list<string>
 */
function ccr_image_extensions(): array
{
    return ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif'];
}

function ccr_find_product_image_file(
    string $sku,
    string $slug,
    string $csvRelative,
    string $migrationProducts,
    string $migrationStorage,
    string $uploadsChristo,
    string $productsFolder
): ?string {
    $candidates = [];

    if ($csvRelative !== '') {
        $rel = ltrim(str_replace(['\\', 'images/'], ['/', ''], $csvRelative), '/');
        $candidates[] = $uploadsChristo . '/' . $rel;
        $candidates[] = $migrationStorage . '/../' . $rel;
        $candidates[] = dirname($migrationStorage, 2) . '/' . $rel;
        $candidates[] = $migrationStorage . '/' . preg_replace('#^storage/#', '', $rel);
    }

    foreach ([$sku, $slug] as $key) {
        if ($key === '') {
            continue;
        }
        foreach (ccr_image_extensions() as $ext) {
            $candidates[] = $migrationProducts . '/' . $key . '.' . $ext;
            $candidates[] = $uploadsChristo . '/storage/products/' . $key . '.' . $ext;
            $candidates[] = $uploadsChristo . '/products/' . $key . '.' . $ext;
        }

        // products/{category}/{slug}.ext
        if (is_dir($productsFolder)) {
            foreach (glob($productsFolder . '/*/' . $key . '.*') ?: [] as $path) {
                $candidates[] = $path;
            }
            foreach (ccr_image_extensions() as $ext) {
                $candidates[] = $productsFolder . '/' . $key . '.' . $ext;
            }
        }
    }

    foreach ($candidates as $path) {
        $real = realpath($path) ?: $path;
        if (is_file($real)) {
            return $real;
        }
    }

    return null;
}

/**
 * Extra shots: slug-1.ext, slug-2.ext, or files in 2024/10 matching slug.
 *
 * @return list<int>
 */
function ccr_find_gallery_images(
    string $sku,
    string $slug,
    string $migrationProducts,
    string $migrationStorage,
    string $productsFolder,
    int $productId
): array {
    $ids = [];
    $keys = array_filter([$sku, $slug]);

    foreach ($keys as $key) {
        for ($i = 1; $i <= 8; $i++) {
            foreach (ccr_image_extensions() as $ext) {
                foreach ([
                    $migrationProducts . '/' . $key . '-' . $i . '.' . $ext,
                    $migrationStorage . '/2024/10/' . $key . '-' . $i . '.' . $ext,
                ] as $path) {
                    if (is_file($path)) {
                        $id = ccr_sideload_image($path, $productId);
                        if ($id) {
                            $ids[] = $id;
                        }
                    }
                }
            }
        }

        if (is_dir($productsFolder)) {
            foreach (glob($productsFolder . '/*/' . $key . '-*.*') ?: [] as $path) {
                if (is_file($path)) {
                    $id = ccr_sideload_image($path, $productId);
                    if ($id) {
                        $ids[] = $id;
                    }
                }
            }
        }
    }

    return array_values(array_unique($ids));
}

function ccr_sideload_image(string $source, int $productId): ?int
{
    static $cache = [];

    $source = realpath($source) ?: $source;
    if (isset($cache[$source])) {
        return $cache[$source];
    }

    if (! is_file($source)) {
        return null;
    }

    $upload = wp_upload_bits(basename($source), null, (string) file_get_contents($source));
    if (! empty($upload['error'])) {
        return null;
    }

    $filetype = wp_check_filetype(basename($source));
    $attachmentId = wp_insert_attachment([
        'post_mime_type' => $filetype['type'] ?: 'image/jpeg',
        'post_title' => sanitize_file_name(pathinfo($source, PATHINFO_FILENAME)),
        'post_content' => '',
        'post_status' => 'inherit',
    ], $upload['file'], $productId);

    if (is_wp_error($attachmentId) || ! $attachmentId) {
        return null;
    }

    $meta = wp_generate_attachment_metadata((int) $attachmentId, $upload['file']);
    wp_update_attachment_metadata((int) $attachmentId, $meta);
    $cache[$source] = (int) $attachmentId;

    return (int) $attachmentId;
}
