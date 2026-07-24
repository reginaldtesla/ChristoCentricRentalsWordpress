<?php
require dirname(__DIR__) . '/wp-load.php';
foreach (wc_get_payment_gateway_ids() as $id) {
    $g = WC()->payment_gateways()->payment_gateways()[$id] ?? null;
    if ($g) {
        echo "{$id}\tenabled={$g->enabled}\t{$g->title}\n";
    }
}
echo 'guest_checkout=' . get_option('woocommerce_enable_guest_checkout') . "\n";
echo 'signup_from_checkout=' . get_option('woocommerce_enable_signup_and_login_from_checkout') . "\n";
