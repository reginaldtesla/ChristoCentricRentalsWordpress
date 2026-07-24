<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

require dirname(__DIR__) . '/wp-load.php';
require ABSPATH . 'wp-admin/includes/plugin.php';

$plugins = [
    'woocommerce/woocommerce.php',
    'christocentric-rentals/christocentric-rentals.php',
];

foreach ($plugins as $plugin) {
    if (is_plugin_active($plugin)) {
        echo "Already active: {$plugin}\n";
        continue;
    }

    $result = activate_plugin($plugin);
    if (is_wp_error($result)) {
        fwrite(STDERR, "Failed {$plugin}: " . $result->get_error_message() . "\n");
        exit(1);
    }

    echo "Activated: {$plugin}\n";
}

switch_theme('christocentric');
echo "Active theme: christocentric\n";
