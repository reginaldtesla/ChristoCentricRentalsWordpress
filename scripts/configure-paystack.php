<?php
/**
 * Configure Paystack (test keys from Laravel .env) + store GHS defaults.
 * Run: php scripts/configure-paystack.php
 *
 * Does not print secret keys. Switch to live keys in WP admin before production.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

require dirname(__DIR__) . '/wp-load.php';

if (! class_exists('WooCommerce')) {
    fwrite(STDERR, "WooCommerce required.\n");
    exit(1);
}

$laravelEnv = 'C:/laragon/www/ChristocentricRentals/.env';
$env = [];
if (is_file($laravelEnv)) {
    foreach (file($laravelEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $env[$k] = trim($v, " \t\"'");
    }
}

$testPk = $env['PAYSTACK_PUBLIC_KEY'] ?? '';
$testSk = $env['PAYSTACK_SECRET_KEY'] ?? '';

if ($testPk === '' || $testSk === '') {
    fwrite(STDERR, "No Paystack keys found in Laravel .env. Add them in WooCommerce → Settings → Payments → Paystack.\n");
    exit(1);
}

$isTest = str_starts_with($testPk, 'pk_test_');

update_option('woocommerce_currency', 'GHS');
update_option('woocommerce_default_country', 'GH');
update_option('woocommerce_allowed_countries', 'specific');
update_option('woocommerce_specific_allowed_countries', ['GH']);
update_option('woocommerce_ship_to_countries', 'specific');
update_option('woocommerce_specific_ship_to_countries', ['GH']);

$settings = get_option('woocommerce_paystack_settings', []);
if (! is_array($settings)) {
    $settings = [];
}

$settings['enabled'] = 'yes';
$settings['title'] = $settings['title'] ?? 'Paystack (Card / Mobile Money)';
$settings['description'] = $settings['description'] ?? 'Pay securely with card or mobile money via Paystack.';
$settings['testmode'] = $isTest ? 'yes' : 'no';
$settings['payment_page'] = $settings['payment_page'] ?: 'redirect';
$settings['autocomplete_order'] = 'no'; // keep as Processing for rental ops

if ($isTest) {
    $settings['test_public_key'] = $testPk;
    $settings['test_secret_key'] = $testSk;
} else {
    $settings['live_public_key'] = $testPk;
    $settings['live_secret_key'] = $testSk;
    $settings['testmode'] = 'no';
}

update_option('woocommerce_paystack_settings', $settings);

// Ensure pickup cash stays on.
$pickup = get_option('woocommerce_ccr_pickup_cash_settings', []);
if (! is_array($pickup)) {
    $pickup = [];
}
$pickup['enabled'] = 'yes';
$pickup['title'] = $pickup['title'] ?? 'Pay on pickup (cash)';
update_option('woocommerce_ccr_pickup_cash_settings', $pickup);

echo "=== Paystack configured ===\n";
echo 'Currency: GHS' . PHP_EOL;
echo 'Mode: ' . ($isTest ? 'TEST' : 'LIVE') . PHP_EOL;
echo 'Public key prefix: ' . substr($testPk, 0, 10) . '…' . PHP_EOL;
echo 'Pickup cash: enabled' . PHP_EOL;
echo "\nAdmin: WooCommerce → Settings → Payments → Paystack\n";
echo "Before go-live: paste live keys and uncheck Test mode.\n";
echo "Webhook (Paystack dashboard): " . home_url('/?wc-api=wc_gateway_paystack') . "\n";
