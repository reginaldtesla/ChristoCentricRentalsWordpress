<?php
require dirname(__DIR__) . '/wp-load.php';

echo "WordPress admin check\n";
echo "=====================\n";
echo 'Site URL: ' . site_url() . "\n";
echo 'Admin URL: ' . admin_url() . "\n";
echo 'Active plugins: ' . implode(', ', (array) get_option('active_plugins', [])) . "\n";
echo 'WooCommerce: ' . (class_exists('WooCommerce') ? 'yes' : 'no') . "\n";
echo 'Christocentric plugin: ' . (class_exists('Christocentric_Rentals') ? 'yes' : 'no') . "\n";
echo 'Pickup gateway wrapper: ' . (class_exists('CCR_Pickup_Cash_Gateway') ? 'yes' : 'no') . "\n";
echo 'Products: ' . (int) wp_count_posts('product')->publish . "\n";
echo 'Orders: ' . (int) wp_count_posts('shop_order')->publish . "\n";

$admins = get_users(['role' => 'administrator', 'number' => 5]);
echo 'Administrators: ' . count($admins) . "\n";
foreach ($admins as $user) {
    echo '  - ' . $user->user_login . ' (' . $user->user_email . ")\n";
}

if (function_exists('wc_get_payment_gateway_ids')) {
    echo 'Payment gateways: ' . implode(', ', wc_get_payment_gateway_ids()) . "\n";
}

echo "Custom menu: WooCommerce → Christocentric Rentals → " . admin_url('admin.php?page=christocentric-rentals') . "\n";
