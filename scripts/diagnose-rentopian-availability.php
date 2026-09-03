<?php
/**
 * One-off CLI diagnostic: php scripts/diagnose-rentopian-availability.php
 */
require dirname(__DIR__) . '/wp-load.php';

if (! defined('ABSPATH')) {
    fwrite(STDERR, "WP failed to load\n");
    exit(1);
}

global $wpdb;

echo "siteurl=" . get_option('siteurl') . PHP_EOL;
echo "rental_hide_zip=" . var_export(get_option('rental_hide_zip'), true) . PHP_EOL;
echo "rental_allow_overbook=" . var_export(get_option('rental_allow_overbook'), true) . PHP_EOL;
echo "rental_dates_on_checkout=" . var_export(get_option('rental_dates_on_checkout'), true) . PHP_EOL;
echo "api_key_set=" . (get_option('rental_api_key') ? 'yes' : 'no') . PHP_EOL;

$counts = $wpdb->get_row(
    "SELECT COUNT(*) AS total,
            SUM(CASE WHEN pm.meta_value IS NULL OR pm.meta_value='' OR pm.meta_value='0' THEN 1 ELSE 0 END) AS missing
     FROM {$wpdb->posts} p
     LEFT JOIN {$wpdb->postmeta} pm
       ON p.ID = pm.post_id AND pm.meta_key = '_rental_inventory_id'
     WHERE p.post_type = 'product' AND p.post_status = 'publish'"
);

echo "products_total=" . (int) ($counts->total ?? 0) . PHP_EOL;
echo "products_missing_inventory_id=" . (int) ($counts->missing ?? 0) . PHP_EOL;

$sample = $wpdb->get_results(
    "SELECT p.ID, p.post_title, pm.meta_value AS inv_id
     FROM {$wpdb->posts} p
     LEFT JOIN {$wpdb->postmeta} pm
       ON p.ID = pm.post_id AND pm.meta_key = '_rental_inventory_id'
     WHERE p.post_type = 'product' AND p.post_status = 'publish'
     ORDER BY p.ID DESC
     LIMIT 8"
);

foreach ($sample as $row) {
    echo "sample id={$row->ID} inv=" . var_export($row->inv_id, true) . " title={$row->post_title}" . PHP_EOL;
}

if (! function_exists('rental_check_availability')) {
    echo "rental_check_availability=missing" . PHP_EOL;
    exit(0);
}

$inv = null;
foreach ($sample as $row) {
    if (! empty($row->inv_id) && (int) $row->inv_id > 0) {
        $inv = (int) $row->inv_id;
        break;
    }
}

if (! $inv) {
    echo "availability_probe=skipped (no inventory ids)" . PHP_EOL;
    exit(0);
}

// Mimic cookies if present in CLI env (usually none).
$_COOKIE['rental_start_date'] = $_COOKIE['rental_start_date'] ?? (date('Y/m/d') . ' 04:00 PM');
$_COOKIE['rental_end_date'] = $_COOKIE['rental_end_date'] ?? (date('Y/m/d', strtotime('+1 day')) . ' 04:00 PM');
$_COOKIE['rental_zip'] = $_COOKIE['rental_zip'] ?? '1';

echo "probe_inv={$inv}" . PHP_EOL;
try {
    $availability = rental_check_availability([$inv]);
    echo "availability_type=" . gettype($availability) . PHP_EOL;
    echo "availability=" . substr(wp_json_encode($availability), 0, 1000) . PHP_EOL;
} catch (Throwable $e) {
    echo "availability_exception=" . $e->getMessage() . PHP_EOL;
}
