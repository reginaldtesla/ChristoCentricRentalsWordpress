<?php
/**
 * Attach related product images (cutouts, numbered variants, folder shots)
 * into WooCommerce product galleries. Featured image stays as-is (original).
 *
 * Run: php scripts/sync-product-galleries.php
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

echo "=== Sync product galleries ===\n";

$updated = 0;
$skipped = 0;
$missing = 0;
$cache = [];

foreach (wc_get_products(['limit' => -1, 'status' => 'publish']) as $product) {
    $productId = $product->get_id();
    $sku = (string) $product->get_sku();
    $slug = (string) $product->get_slug();
    $laravelSlug = (string) get_post_meta($productId, '_ccr_laravel_slug', true);
    $keys = array_values(array_unique(array_filter([$laravelSlug, $slug, $sku])));

    $files = ccr_collect_related_images($keys, $sources, $exts);
    if ($files === []) {
        $missing++;
        continue;
    }

    $featuredId = (int) $product->get_image_id();
    $featuredFile = $featuredId ? strtolower((string) basename((string) get_attached_file($featuredId))) : '';

    $galleryIds = [];
    foreach ($files as $file) {
        $baseName = strtolower(basename($file));
        // Don't re-attach the exact same file already used as featured.
        if ($featuredFile !== '' && $baseName === $featuredFile) {
            continue;
        }

        $id = ccr_gallery_sideload($file, $productId, $cache);
        if ($id && $id !== $featuredId) {
            $galleryIds[] = $id;
        }
    }

    $galleryIds = array_values(array_unique($galleryIds));
    if ($galleryIds === []) {
        $skipped++;
        echo "No extras: {$product->get_name()}\n";
        continue;
    }

    update_post_meta($productId, '_product_image_gallery', implode(',', $galleryIds));
    $updated++;
    echo "Gallery: {$product->get_name()} ← " . count($galleryIds) . " related (" . implode(', ', array_map('basename', $files)) . ")\n";
}

echo "\nUpdated: {$updated}\nSkipped: {$skipped}\nNo source files: {$missing}\nDone.\n";

/**
 * @param list<string> $keys
 * @param list<string> $sources
 * @param list<string> $exts
 * @return list<string>
 */
function ccr_collect_related_images(array $keys, array $sources, array $exts): array
{
    $found = [];

    foreach ($keys as $key) {
        $key = trim($key);
        if ($key === '') {
            continue;
        }

        foreach ($sources as $dir) {
            // Numbered angle variants only (not cutouts — those duplicate the main shot).
            for ($i = 1; $i <= 8; $i++) {
                foreach ($exts as $ext) {
                    $path = $dir . DIRECTORY_SEPARATOR . $key . '-' . $i . '.' . $ext;
                    if (is_file($path)) {
                        $found[strtolower(basename($path))] = realpath($path) ?: $path;
                        break;
                    }
                }
            }

            // Subfolder gallery (skip cutout files)
            $subdir = $dir . DIRECTORY_SEPARATOR . $key;
            if (is_dir($subdir)) {
                foreach (scandir($subdir) ?: [] as $file) {
                    if ($file === '.' || $file === '..') {
                        continue;
                    }
                    if (stripos($file, 'cutout') !== false) {
                        continue;
                    }
                    if (! preg_match('/\.(jpe?g|png|webp|avif|gif)$/i', $file)) {
                        continue;
                    }
                    $path = $subdir . DIRECTORY_SEPARATOR . $file;
                    if (is_file($path)) {
                        $found[strtolower($file)] = realpath($path) ?: $path;
                    }
                }
            }
        }
    }

    return array_values($found);
}

/**
 * @param array<string,int> $cache
 */
function ccr_gallery_sideload(string $source, int $productId, array &$cache): ?int
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
        'post_mime_type' => $filetype['type'] ?: 'image/png',
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
