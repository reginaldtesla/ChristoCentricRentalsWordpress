<?php
/**
 * Create newsletter table and flush rewrite rules (idempotent).
 */
require dirname(__DIR__) . '/wp-load.php';

if (! class_exists('CCR_Newsletter')) {
    fwrite(STDERR, "Christocentric Rentals plugin is not loaded.\n");
    exit(1);
}

CCR_Newsletter::install();

echo "Newsletter table ready.\n";
echo "Unsubscribe URL pattern: " . home_url('/newsletter/unsubscribe/{token}/') . "\n";
echo "Admin: " . admin_url('admin.php?page=ccr-newsletter-subscribers') . "\n";
