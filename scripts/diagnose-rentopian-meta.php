<?php
require dirname(__DIR__) . '/wp-load.php';
global $wpdb;
$keys = $wpdb->get_col(
    "SELECT DISTINCT meta_key FROM {$wpdb->postmeta}
     WHERE meta_key LIKE '%rental%' OR meta_key LIKE '%inventory%'
     ORDER BY meta_key ASC
     LIMIT 80"
);
echo implode(PHP_EOL, $keys) . PHP_EOL;
$any = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_rental_inventory_id' AND meta_value <> '' AND meta_value <> '0'"
);
echo "rows_with_inventory_id={$any}" . PHP_EOL;
$rel = (int) $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}rental_product_relations'");
echo "product_relations_table=" . ($rel ? 'yes' : 'no') . PHP_EOL;
if ($rel) {
    echo "product_relations_count=" . (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rental_product_relations") . PHP_EOL;
}
