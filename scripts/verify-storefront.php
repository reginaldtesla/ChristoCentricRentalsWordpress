<?php
require dirname(__DIR__) . '/wp-load.php';

$n = 0;
foreach (wc_get_products(['limit' => 50, 'status' => 'publish']) as $p) {
    if (function_exists('ccr_product_is_on_sale') && ccr_product_is_on_sale($p)) {
        echo 'SALE #' . $p->get_id() . ' ' . $p->get_name() . ' '
            . ccr_product_regular_daily_price($p) . '→' . ccr_product_daily_price($p) . PHP_EOL;
        $n++;
    }
}
echo "on_sale_count={$n}\n";

if (class_exists('CCR_Product_Kits')) {
    foreach (wc_get_products(['limit' => 5, 'meta_key' => '_ccr_is_kit', 'meta_value' => 'yes']) as $k) {
        echo 'KIT #' . $k->get_id() . ' ' . $k->get_name() . ' items=' . count(CCR_Product_Kits::get_items($k->get_id())) . PHP_EOL;
    }
}

foreach (['about', 'faq', 'contact', 'terms', 'privacy', 'help'] as $s) {
    $page = get_page_by_path($s);
    echo $s . '=' . ($page ? 'ok#' . $page->ID : 'MISS') . ' url=' . home_url('/' . $s . '/') . PHP_EOL;
}

echo 'shop=' . wc_get_page_permalink('shop') . PHP_EOL;
echo 'theme=' . get_stylesheet() . PHP_EOL;
echo 'plugins=' . implode(', ', (array) get_option('active_plugins')) . PHP_EOL;
