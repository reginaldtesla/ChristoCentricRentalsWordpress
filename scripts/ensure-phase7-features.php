<?php
/**
 * Bootstrap new Phase-7 features: compare page, hold cron, default options.
 * Run: php scripts/ensure-phase7-features.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

require_once dirname(__DIR__) . '/wp-load.php';

$defaults = [
    'ccr_grace_minutes' => 30,
    'ccr_daily_rate_multiplier' => 1,
    'ccr_due_soon_hours' => 24,
    'ccr_global_discount_percent' => 0,
    'ccr_banner_title' => 'Gear Sale',
    'ccr_banner_line_1' => '{percent}% off every rental — cameras, lenses, lights, and more.',
    'ccr_banner_line_2' => 'Discount applies automatically at checkout. No coupon needed.',
    'ccr_banner_cta' => 'Shop',
];

foreach ($defaults as $key => $value) {
    if (get_option($key, null) === false || get_option($key, null) === null) {
        add_option($key, $value);
        echo "option {$key}=default\n";
    }
}

if (! get_page_by_path('compare')) {
    $id = wp_insert_post([
        'post_title' => 'Compare',
        'post_name' => 'compare',
        'post_status' => 'publish',
        'post_type' => 'page',
    ], true);
    echo is_wp_error($id) ? $id->get_error_message() . "\n" : "Created Compare page {$id}\n";
} else {
    echo "Compare page OK\n";
}

if (class_exists('CCR_Hold_Expiry')) {
    CCR_Hold_Expiry::schedule();
    echo 'Hold expiry cron: ' . (wp_next_scheduled(CCR_Hold_Expiry::HOOK) ? 'scheduled' : 'FAILED') . "\n";
}

// Activate Rank Math if present.
$rankMath = 'seo-by-rank-math/rank-math.php';
if (file_exists(WP_PLUGIN_DIR . '/' . $rankMath)) {
    $active = (array) get_option('active_plugins', []);
    if (! in_array($rankMath, $active, true)) {
        $active[] = $rankMath;
        update_option('active_plugins', array_values($active));
        echo "Activated Rank Math SEO\n";
    } else {
        echo "Rank Math already active\n";
    }
} else {
    echo "Rank Math not installed yet — install via Plugins → Add New → search Rank Math, or approve the download.\n";
}

echo "Done.\n";
