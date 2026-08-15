<?php
require dirname(__DIR__) . '/wp-load.php';

$out = fopen(dirname(__DIR__) . '/products/product-list.csv', 'w');
fputcsv($out, ['id', 'sku', 'name', 'slug', 'category']);

$products = wc_get_products([
    'limit' => -1,
    'status' => 'publish',
    'orderby' => 'title',
    'order' => 'ASC',
]);

foreach ($products as $p) {
    $terms = get_the_terms($p->get_id(), 'product_cat');
    $cat = ($terms && ! is_wp_error($terms)) ? $terms[0]->name : '';
    fputcsv($out, [
        $p->get_id(),
        $p->get_sku(),
        $p->get_name(),
        $p->get_slug(),
        $cat,
    ]);
}

fclose($out);
echo 'Wrote ' . count($products) . " products to products/product-list.csv\n";
