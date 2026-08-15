<?php
/**
 * Ensure storefront pages, permalinks, sample sales & kits are wired.
 * Run: php scripts/ensure-storefront.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

$wpRoot = dirname(__DIR__);
require_once $wpRoot . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

echo "=== Ensure storefront ===\n";

if (! class_exists('WooCommerce')) {
    fwrite(STDERR, "WooCommerce is not active.\n");
    exit(1);
}

// Permalinks
update_option('permalink_structure', '/%postname%/');
flush_rewrite_rules(false);
echo "Permalinks flushed\n";

$pages = [
    'about' => ['title' => 'About', 'template' => 'page-about.php'],
    'faq' => ['title' => 'FAQ', 'template' => 'page-faq.php'],
    'contact' => ['title' => 'Contact', 'template' => 'page-contact.php'],
    'terms' => ['title' => 'Terms & Conditions', 'template' => 'page-terms.php'],
    'privacy' => ['title' => 'Privacy Policy', 'template' => 'page-privacy.php'],
    'help' => ['title' => 'Help & Support', 'template' => 'page-help.php'],
];

foreach ($pages as $slug => $meta) {
    $existing = get_page_by_path($slug);

    if ($existing instanceof WP_Post) {
        update_post_meta($existing->ID, '_wp_page_template', $meta['template']);
        if ($existing->post_status !== 'publish') {
            wp_update_post(['ID' => $existing->ID, 'post_status' => 'publish']);
        }
        echo "Page OK: /{$slug}/ (#{$existing->ID})\n";
        continue;
    }

    $id = wp_insert_post([
        'post_title' => $meta['title'],
        'post_name' => $slug,
        'post_status' => 'publish',
        'post_type' => 'page',
        'post_content' => '',
    ], true);

    if (is_wp_error($id)) {
        fwrite(STDERR, "Failed {$slug}: " . $id->get_error_message() . "\n");
        continue;
    }

    update_post_meta($id, '_wp_page_template', $meta['template']);
    echo "Created page: /{$slug}/ (#{$id})\n";
}

// Woo pages
$shopId = (int) wc_get_page_id('shop');
if ($shopId > 0) {
    echo "Shop page: #" . $shopId . " → " . get_permalink($shopId) . "\n";
}

// Apply sample sale prices to a few products (only if none on sale yet)
$saleCount = 0;
foreach (wc_get_products(['limit' => 50, 'status' => 'publish']) as $product) {
    $saleMeta = get_post_meta($product->get_id(), '_ccr_sale_price_per_day', true);
    if ($saleMeta !== '' && (float) $saleMeta > 0) {
        $saleCount++;
    }
}

if ($saleCount === 0) {
    $candidates = wc_get_products(['limit' => 12, 'status' => 'publish', 'orderby' => 'date', 'order' => 'DESC']);
    $marked = 0;

    foreach ($candidates as $product) {
        $regular = (float) get_post_meta($product->get_id(), '_ccr_price_per_day', true);

        if ($regular <= 0) {
            $regular = (float) $product->get_regular_price();
        }

        if ($regular < 20) {
            continue;
        }

        $sale = round($regular * 0.85, 2);
        update_post_meta($product->get_id(), '_ccr_price_per_day', $regular);
        update_post_meta($product->get_id(), '_ccr_sale_price_per_day', $sale);
        update_post_meta($product->get_id(), '_regular_price', $regular);
        update_post_meta($product->get_id(), '_sale_price', $sale);
        update_post_meta($product->get_id(), '_price', $sale);
        wc_delete_product_transients($product->get_id());
        $marked++;
        echo "Sale: {$product->get_name()} {$regular} → {$sale}/day\n";

        if ($marked >= 4) {
            break;
        }
    }

    if ($marked === 0) {
        echo "No suitable products for sample sales\n";
    }
} else {
    echo "Sale products already present ({$saleCount}) — skipped sample sales\n";
}

// Create a sample lighting kit if none exists
$kits = wc_get_products([
    'limit' => 1,
    'status' => 'publish',
    'meta_key' => '_ccr_is_kit',
    'meta_value' => 'yes',
]);

if ($kits === []) {
    $components = wc_get_products([
        'limit' => 3,
        'status' => 'publish',
        'stock_status' => 'instock',
        'category' => ['continuous-light', 'lighting', 'lights'],
    ]);

    if (count($components) < 2) {
        $components = wc_get_products(['limit' => 3, 'status' => 'publish', 'stock_status' => 'instock']);
    }

    if (count($components) >= 2) {
        $items = [];
        $dailyTotal = 0.0;

        foreach (array_slice($components, 0, 3) as $component) {
            $items[] = ['product_id' => $component->get_id(), 'quantity' => 1];
            $d = (float) get_post_meta($component->get_id(), '_ccr_price_per_day', true);
            if ($d <= 0) {
                $d = (float) $component->get_price();
            }
            $dailyTotal += $d;
        }

        $kitId = wp_insert_post([
            'post_title' => 'Starter Lighting Kit',
            'post_name' => 'starter-lighting-kit',
            'post_status' => 'publish',
            'post_type' => 'product',
            'post_content' => 'A ready-to-rent lighting kit. Adding this kit places every included item in your cart with the same rental dates.',
        ], true);

        if (! is_wp_error($kitId)) {
            wp_set_object_terms($kitId, 'simple', 'product_type');
            update_post_meta($kitId, '_ccr_is_kit', 'yes');
            update_post_meta($kitId, '_ccr_kit_items', $items);
            update_post_meta($kitId, '_ccr_price_per_day', round($dailyTotal, 2));
            update_post_meta($kitId, '_regular_price', round($dailyTotal, 2));
            update_post_meta($kitId, '_price', round($dailyTotal, 2));
            update_post_meta($kitId, '_manage_stock', 'no');
            update_post_meta($kitId, '_stock_status', 'instock');
            update_post_meta($kitId, '_ccr_is_featured', 'yes');
            update_post_meta($kitId, '_visibility', 'visible');
            echo "Created kit #{$kitId}: Starter Lighting Kit (" . count($items) . " items, ₵" . round($dailyTotal, 2) . "/day)\n";
        }
    } else {
        echo "Not enough products to build a sample kit\n";
    }
} else {
    echo "Kit product already present — skipped sample kit\n";
}

// Newsletter table
if (class_exists('CCR_Newsletter')) {
    CCR_Newsletter::install();
    echo "Newsletter table OK\n";
}

echo "\nURLs\n";
echo 'Home: ' . home_url('/') . "\n";
echo 'Shop: ' . wc_get_page_permalink('shop') . "\n";
echo 'About: ' . home_url('/about/') . "\n";
echo 'FAQ: ' . home_url('/faq/') . "\n";
echo 'Contact: ' . home_url('/contact/') . "\n";
echo 'Terms: ' . home_url('/terms/') . "\n";
echo 'Privacy: ' . home_url('/privacy/') . "\n";
echo "Done.\n";
