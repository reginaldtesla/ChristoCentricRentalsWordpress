<?php
require __DIR__ . '/../wp-load.php';

if (! function_exists('ccr_nav_category_groups')) {
    fwrite(STDERR, "ccr_nav_category_groups missing\n");
    exit(1);
}

foreach (ccr_nav_category_groups() as $group) {
    echo $group['label'] . PHP_EOL;
    foreach ($group['items'] as $item) {
        echo '  - ' . $item['label'] . ' (' . ($item['slug'] ?? '') . ')' . PHP_EOL;
    }
}
