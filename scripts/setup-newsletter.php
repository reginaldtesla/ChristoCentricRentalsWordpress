<?php
/**
 * One-time newsletter setup: DB table, rewrite rules, default email options.
 */
require dirname(__DIR__) . '/wp-load.php';

if (! class_exists('CCR_Newsletter')) {
    fwrite(STDERR, "Christocentric Rentals plugin is not loaded.\n");
    exit(1);
}

CCR_Newsletter::install();

$defaults = [
    'ccr_newsletter_send_welcome' => 'yes',
    'ccr_newsletter_notify_admin' => 'yes',
    'ccr_newsletter_notify_email' => 'christocentricrentals@gmail.com',
    'ccr_newsletter_from_name' => 'Christocentric Rentals',
    'ccr_newsletter_from_email' => 'christocentricrentals@gmail.com',
];

foreach ($defaults as $key => $value) {
    if (get_option($key, false) === false) {
        add_option($key, $value);
        echo "Added option: {$key}\n";
    } else {
        echo "Option exists: {$key}\n";
    }
}

echo "\nNewsletter ready.\n";
echo 'Subscribers admin: ' . admin_url('admin.php?page=ccr-newsletter-subscribers') . "\n";
echo 'Settings: ' . admin_url('admin.php?page=christocentric-rentals') . "\n";
echo 'Unsubscribe URL: ' . home_url('/newsletter/unsubscribe/{token}/') . "\n";
