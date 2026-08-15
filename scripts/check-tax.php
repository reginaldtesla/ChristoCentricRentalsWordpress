<?php
require dirname(__DIR__) . '/wp-load.php';

echo 'calc_taxes=' . get_option('woocommerce_calc_taxes') . PHP_EOL;
echo 'prices_include_tax=' . get_option('woocommerce_prices_include_tax') . PHP_EOL;
echo 'tax_based_on=' . get_option('woocommerce_tax_based_on') . PHP_EOL;
echo 'default_country=' . get_option('woocommerce_default_country') . PHP_EOL;
echo 'shop_display=' . get_option('woocommerce_tax_display_shop') . PHP_EOL;
echo 'cart_display=' . get_option('woocommerce_tax_display_cart') . PHP_EOL;
echo 'tax_total_display=' . get_option('woocommerce_tax_total_display') . PHP_EOL;
echo 'wc_tax_enabled=' . (function_exists('wc_tax_enabled') && wc_tax_enabled() ? 'yes' : 'no') . PHP_EOL;

global $wpdb;
$rates = $wpdb->get_results('SELECT * FROM ' . $wpdb->prefix . 'woocommerce_tax_rates');
echo 'tax_rates_count=' . count($rates) . PHP_EOL;
foreach ($rates as $r) {
    echo sprintf(
        "rate_id=%s country=%s state=%s rate=%s name=%s class=%s priority=%s compound=%s shipping=%s\n",
        $r->tax_rate_id,
        $r->tax_rate_country,
        $r->tax_rate_state,
        $r->tax_rate,
        $r->tax_rate_name,
        $r->tax_rate_class,
        $r->tax_rate_priority,
        $r->tax_rate_compound,
        $r->tax_rate_shipping
    );
}

// Sample product tax status
$products = wc_get_products(['limit' => 3, 'status' => 'publish']);
foreach ($products as $p) {
    echo sprintf(
        "product=%s tax_status=%s tax_class=%s price=%s\n",
        $p->get_id(),
        $p->get_tax_status(),
        $p->get_tax_class() ?: 'standard',
        $p->get_price()
    );
}
