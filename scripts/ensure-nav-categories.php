<?php
require __DIR__ . '/../wp-load.php';
if (! function_exists('ccr_ensure_nav_categories')) {
    fwrite(STDERR, "ccr_ensure_nav_categories missing\n");
    exit(1);
}
ccr_ensure_nav_categories();
echo 'opt=' . get_option('ccr_nav_categories_v2') . PHP_EOL;
foreach (['strobes', 'flash', 'video-switcher', 'transmitter', 'live-streaming-gears', 'storage'] as $s) {
    $t = get_term_by('slug', $s, 'product_cat');
    echo $s . '=' . ($t instanceof WP_Term ? (string) $t->term_id : 'missing') . PHP_EOL;
}
