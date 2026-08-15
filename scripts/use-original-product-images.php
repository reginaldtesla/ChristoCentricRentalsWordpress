<?php
/**
 * Set each product's featured image to the Laravel ORIGINAL (non-cutout) photo.
 *
 * Run: php scripts/use-original-product-images.php
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
    fwrite(STDERR, "WooCommerce required.\n");
    exit(1);
}

$sources = array_values(array_filter([
    'C:/laragon/www/ChristocentricRentals/public/images/storage/products',
    $wpRoot . '/wp-content/uploads/christocentric/storage/products',
    $wpRoot . '/migration/images/storage/products',
], 'is_dir'));

$exts = ['jpg', 'jpeg', 'webp', 'png', 'avif', 'gif'];

echo "=== Use original (non-cutout) product images ===\n";
echo 'Sources: ' . implode(', ', $sources) . "\n\n";

$updated = 0;
$missing = 0;
$failed = 0;
$cache = [];

foreach (wc_get_products(['limit' => -1, 'status' => 'publish']) as $product) {
    $productId = $product->get_id();
    $sku = (string) $product->get_sku();
    $slug = (string) ($product->get_slug() ?: '');
    $laravelSlug = (string) get_post_meta($productId, '_ccr_laravel_slug', true);

    $keys = array_values(array_unique(array_filter([$laravelSlug, $slug, $sku])));
    $original = ccr_find_original_image($keys, $sources, $exts);

    if ($original === null) {
        echo "Missing original: {$product->get_name()} (" . implode('|', $keys) . ")\n";
        $missing++;
        continue;
    }

    $attachmentId = ccr_sideload_cached($original, $productId, $cache);
    if (! $attachmentId) {
        echo "Failed: {$product->get_name()} ← " . basename($original) . "\n";
        $failed++;
        continue;
    }

    set_post_thumbnail($productId, $attachmentId);
    $updated++;
    echo "OK: {$product->get_name()} ← " . basename($original) . "\n";
}

echo "\nUpdated: {$updated}\nMissing: {$missing}\nFailed: {$failed}\nDone.\n";

/**
 * @param list<string> $keys
 * @param list<string> $sources
 * @param list<string> $exts
 */
function ccr_find_original_image(array $keys, array $sources, array $exts): ?string
{
    foreach ($keys as $key) {
        $key = trim($key);
        if ($key === '') {
            continue;
        }

        foreach ($sources as $dir) {
            // Prefer exact non-cutout: {key}.{ext}
            foreach ($exts as $ext) {
                $path = $dir . DIRECTORY_SEPARATOR . $key . '.' . $ext;
                if (is_file($path)) {
                    return realpath($path) ?: $path;
                }
            }

            // Folder style: {key}/main.* or first non-cutout file
            $subdir = $dir . DIRECTORY_SEPARATOR . $key;
            if (is_dir($subdir)) {
                foreach ($exts as $ext) {
                    foreach (['main', $key, 'featured', '1'] as $stem) {
                        $path = $subdir . DIRECTORY_SEPARATOR . $stem . '.' . $ext;
                        if (is_file($path)) {
                            return realpath($path) ?: $path;
                        }
                    }
                }
                foreach (scandir($subdir) ?: [] as $file) {
                    if ($file === '.' || $file === '..') {
                        continue;
                    }
                    if (stripos($file, 'cutout') !== false) {
                        continue;
                    }
                    $path = $subdir . DIRECTORY_SEPARATOR . $file;
                    if (is_file($path) && preg_match('/\.(jpe?g|png|webp|avif|gif)$/i', $file)) {
                        return realpath($path) ?: $path;
                    }
                }
            }
        }
    }

    return null;
}

/**
 * @param array<string,int> $cache
 */
function ccr_sideload_cached(string $source, int $productId, array &$cache): ?int
{
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
