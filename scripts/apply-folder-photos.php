<?php
/**
 * Attach Products Images folders to WooCommerce products.
 */
require dirname(__DIR__) . '/wp-load.php';

if (! class_exists('CCR_Rentopian_Catalog')) {
    fwrite(STDERR, "plugin not loaded\n");
    exit(1);
}

@set_time_limit(0);
$result = CCR_Rentopian_Catalog::apply_folder_photos(true);
echo ($result['message'] ?? '') . PHP_EOL;
echo 'products=' . (int) ($result['products'] ?? 0) . ' photos=' . (int) ($result['photos'] ?? 0) . ' skipped=' . (int) ($result['skipped'] ?? 0) . PHP_EOL;
