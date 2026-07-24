<?php
/**
 * Smoke-test newsletter subscribe (CLI).
 */
require dirname(__DIR__) . '/wp-load.php';

if (! class_exists('CCR_Newsletter')) {
    fwrite(STDERR, "CCR_Newsletter not loaded.\n");
    exit(1);
}

$email = 'test-newsletter-' . time() . '@example.com';
$result = CCR_Newsletter::subscribe($email);
$ok = $result['ok'];

global $wpdb;
$row = $wpdb->get_row($wpdb->prepare(
    'SELECT * FROM ' . CCR_Newsletter::table_name() . ' WHERE email = %s',
    $email
));

echo $ok ? "Subscribe OK\n" : "Subscribe FAILED\n";
echo 'Is new: ' . ($result['is_new'] ? 'yes' : 'no') . "\n";
echo "Welcome emails: " . get_option('ccr_newsletter_send_welcome', 'yes') . "\n";
echo "Email: {$email}\n";
echo "Token: " . ($row->unsubscribe_token ?? 'none') . "\n";
echo "Active count: " . CCR_Newsletter::active_count() . "\n";

if ($row && $row->unsubscribe_token) {
    $unsub = CCR_Newsletter::unsubscribe_by_token($row->unsubscribe_token);
    echo "Unsubscribe: " . ($unsub ? 'OK' : 'FAILED') . "\n";
}
