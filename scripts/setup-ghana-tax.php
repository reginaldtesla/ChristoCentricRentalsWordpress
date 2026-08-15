<?php
/**
 * Enable Ghana VAT stack: 15% VAT + 2.5% NHIL + 2.5% GETFund = 20%.
 * Run: php scripts/setup-ghana-tax.php
 */
require dirname(__DIR__) . '/wp-load.php';

if (! class_exists('WooCommerce')) {
    fwrite(STDERR, "WooCommerce is not active.\n");
    exit(1);
}

update_option('woocommerce_calc_taxes', 'yes');
update_option('woocommerce_prices_include_tax', 'no');
update_option('woocommerce_tax_based_on', 'base'); // shop base address — works with local pickup
update_option('woocommerce_shipping_tax_class', '');
update_option('woocommerce_tax_round_at_subtotal', 'no');
update_option('woocommerce_tax_display_shop', 'excl');
update_option('woocommerce_tax_display_cart', 'excl');
update_option('woocommerce_price_display_suffix', 'excl. tax');
update_option('woocommerce_tax_total_display', 'itemized');

// Ensure shop is in Ghana (Ashanti / Kumasi area already used by store).
$country = (string) get_option('woocommerce_default_country');
if ($country === '' || strpos($country, 'GH') !== 0) {
    update_option('woocommerce_default_country', 'GH:AH');
}

global $wpdb;
$table = $wpdb->prefix . 'woocommerce_tax_rates';

// Remove existing GH standard rates so we can reinstall cleanly.
$existing = $wpdb->get_col(
    "SELECT tax_rate_id FROM {$table} WHERE tax_rate_country = 'GH' AND tax_rate_class = ''"
);
foreach ($existing as $rateId) {
    $rateId = (int) $rateId;
    $wpdb->delete($wpdb->prefix . 'woocommerce_tax_rate_locations', ['tax_rate_id' => $rateId], ['%d']);
    WC_Tax::_delete_tax_rate($rateId);
}

$rates = [
    ['rate' => '15.0000', 'name' => 'VAT', 'order' => 1],
    ['rate' => '2.5000', 'name' => 'NHIL', 'order' => 2],
    ['rate' => '2.5000', 'name' => 'GETFund', 'order' => 3],
];

foreach ($rates as $rate) {
    $id = WC_Tax::_insert_tax_rate([
        'tax_rate_country' => 'GH',
        'tax_rate_state' => '',
        'tax_rate' => $rate['rate'],
        'tax_rate_name' => $rate['name'],
        'tax_rate_priority' => 1,
        'tax_rate_compound' => 0,
        'tax_rate_shipping' => 0,
        'tax_rate_order' => $rate['order'],
        'tax_rate_class' => '',
    ]);
    echo "Added {$rate['name']} {$rate['rate']}% (id {$id})\n";
}

WC_Cache_Helper::invalidate_cache_group('taxes');
echo "Done. Taxes enabled, based on shop address, itemized at checkout.\n";
echo "Hard-refresh checkout and place a test order.\n";
