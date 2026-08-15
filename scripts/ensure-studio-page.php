<?php
/**
 * Ensure Studio page exists.
 * Run: php scripts/ensure-studio-page.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/wp-load.php';

$page = get_page_by_path('studio');
if ($page) {
    echo 'Studio page already exists (ID ' . (int) $page->ID . ")\n";
    echo get_permalink($page) . "\n";
    exit(0);
}

$id = wp_insert_post([
    'post_title' => 'Studio',
    'post_name' => 'studio',
    'post_status' => 'publish',
    'post_type' => 'page',
], true);

if (is_wp_error($id)) {
    fwrite(STDERR, $id->get_error_message() . "\n");
    exit(1);
}

echo 'Created Studio page (ID ' . (int) $id . ")\n";
echo get_permalink($id) . "\n";
