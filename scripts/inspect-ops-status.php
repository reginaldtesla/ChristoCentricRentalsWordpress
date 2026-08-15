<?php
/**
 * Inspect store readiness for Paystack, stock, email.
 * Run: php scripts/inspect-ops-status.php
 */
require dirname(__DIR__) . '/wp-load.php';

echo "=== Currency / store ===\n";
echo 'currency: ' . get_woocommerce_currency() . "\n";
echo 'country: ' . get_option('woocommerce_default_country') . "\n";
echo 'admin_email: ' . get_option('admin_email') . "\n";

echo "\n=== Paystack ===\n";
$ps = get_option('woocommerce_paystack_settings', []);
if (! is_array($ps)) {
    $ps = [];
}
echo 'enabled: ' . ($ps['enabled'] ?? 'n/a') . "\n";
echo 'testmode: ' . ($ps['testmode'] ?? 'n/a') . "\n";
echo 'has_test_pk: ' . (! empty($ps['test_public_key']) ? 'yes' : 'no') . "\n";
echo 'has_test_sk: ' . (! empty($ps['test_secret_key']) ? 'yes' : 'no') . "\n";
echo 'has_live_pk: ' . (! empty($ps['live_public_key']) ? 'yes' : 'no') . "\n";
echo 'has_live_sk: ' . (! empty($ps['live_secret_key']) ? 'yes' : 'no') . "\n";

echo "\n=== Pickup cash ===\n";
$pc = get_option('woocommerce_ccr_pickup_cash_settings', []);
if (! is_array($pc)) {
    $pc = [];
}
echo 'enabled: ' . ($pc['enabled'] ?? 'n/a') . "\n";

echo "\n=== Stock ===\n";
$manage = $in = $zero = $unmanaged = 0;
foreach (wc_get_products(['limit' => -1, 'status' => 'publish']) as $p) {
    if ($p->get_manage_stock()) {
        $manage++;
        $q = (int) $p->get_stock_quantity();
        if ($q <= 0) {
            $zero++;
        } else {
            $in++;
        }
    } else {
        $unmanaged++;
    }
}
echo "manage_stock={$manage} with_qty={$in} zero={$zero} unmanaged={$unmanaged}\n";

echo "\n=== SMTP plugins ===\n";
foreach (['wp-mail-smtp', 'fluent-smtp', 'post-smtp', 'easy-wp-smtp'] as $slug) {
    echo $slug . ': ' . (is_dir(WP_PLUGIN_DIR . '/' . $slug) ? 'installed' : 'missing') . "\n";
}
