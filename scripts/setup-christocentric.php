<?php
/**
 * One-time local setup: plugins, WooCommerce settings, product import.
 * Run: php scripts/setup-christocentric.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('CLI only.');
}

$wpRoot = dirname(__DIR__);
require_once $wpRoot . '/wp-load.php';

if (! class_exists('WooCommerce')) {
    fwrite(STDERR, "WooCommerce is not active. Activate it first.\n");
    exit(1);
}

require_once WC_ABSPATH . 'includes/wc-product-functions.php';

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

echo "=== Christocentric Rentals WordPress setup ===\n";

// --- Plugins (keep only shop stack) ---
$keep = [
    'woocommerce/woocommerce.php',
    'christocentric-rentals/christocentric-rentals.php',
    'woo-paystack/woo-paystack.php',
];

$active = (array) get_option('active_plugins', []);
$cleaned = array_values(array_intersect($active, $keep));
if ($cleaned !== $active) {
    update_option('active_plugins', $cleaned);
    echo "Cleaned active_plugins (removed missing / unused entries)\n";
}

foreach ($keep as $plugin) {
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

// --- WooCommerce settings ---
update_option('woocommerce_currency', 'GHS');
update_option('woocommerce_currency_pos', 'left');
update_option('woocommerce_price_thousand_sep', ',');
update_option('woocommerce_price_decimal_sep', '.');
update_option('woocommerce_default_country', 'GH:AH');
update_option('woocommerce_allowed_countries', 'specific');
update_option('woocommerce_specific_allowed_countries', ['GH']);
update_option('woocommerce_calc_taxes', 'no');
update_option('woocommerce_enable_guest_checkout', 'no');
update_option('woocommerce_enable_signup_and_login_from_checkout', 'no');
update_option('woocommerce_onboarding_profile', ['completed' => true]);
update_option('woocommerce_task_list_hidden', 'yes');
update_option('woocommerce_task_list_tracked_completed_tasks', [
    'products', 'payments', 'shipping', 'marketing', 'appearance', 'tax',
]);

// Local pickup
update_option('woocommerce_pickup_location_settings', [
    [
        'name' => 'Christocentric Rentals — Bomso',
        'address' => ['address_1' => 'Bomso, Kumasi', 'city' => 'Kumasi', 'state' => 'Ashanti', 'postcode' => '', 'country' => 'GH'],
        'details' => 'Near Abesse Gaming Center. Bring Ghana Card for pickup.',
        'enabled' => true,
    ],
]);

// Permalinks
update_option('permalink_structure', '/%postname%/');
flush_rewrite_rules();

echo "WooCommerce settings applied (GHS, Ghana, permalinks).\n";

// --- Categories ---
$categoriesFile = $wpRoot . '/migration/categories.json';
$categoryMap = [];
if (is_file($categoriesFile)) {
    $categories = json_decode((string) file_get_contents($categoriesFile), true) ?: [];
    foreach ($categories as $cat) {
        $slug = $cat['slug'] ?? sanitize_title($cat['name'] ?? '');
        $name = $cat['name'] ?? $slug;
        $term = get_term_by('slug', $slug, 'product_cat');
        if (! $term) {
            $created = wp_insert_term($name, 'product_cat', ['slug' => $slug]);
            if (! is_wp_error($created)) {
                $categoryMap[$name] = (int) $created['term_id'];
            }
        } else {
            $categoryMap[$name] = (int) $term->term_id;
        }
    }
    echo 'Categories ready: ' . count($categoryMap) . "\n";
}

// --- Copy images if not done ---
$imagesSource = $wpRoot . '/../ChristocentricRentals/public/images';
$imagesTarget = $wpRoot . '/wp-content/uploads/christocentric';
if (is_dir($imagesSource) && ! is_dir($imagesTarget . '/brand')) {
    echo "Copying images from Laravel...\n";
    mkdir($imagesTarget, 0777, true);
    ccr_recursive_copy($imagesSource, $imagesTarget);
}

// --- Import products ---
$csvFile = $wpRoot . '/migration/woocommerce-products.csv';
if (! is_file($csvFile)) {
    fwrite(STDERR, "CSV not found: {$csvFile}\n");
    exit(1);
}

$handle = fopen($csvFile, 'r');
$header = fgetcsv($handle);
$col = array_flip($header);
$imported = 0;
$skipped = 0;
$imageCache = [];

while (($row = fgetcsv($handle)) !== false) {
    if (count($row) < count($header)) {
        continue;
    }

    $sku = $row[$col['SKU']] ?? '';
    if ($sku === '') {
        continue;
    }

    $existing = wc_get_product_id_by_sku($sku);
    if ($existing) {
        $skipped++;
        // Still attach image if product has no featured image yet.
        if (! get_post_thumbnail_id($existing)) {
            $imageRel = $row[$col['Images']] ?? '';
            if ($imageRel !== '') {
                $attachmentId = attach_product_image((int) $existing, $imageRel, $sku, $imagesTarget, $imagesSource, $imageCache);
                if ($attachmentId) {
                    set_post_thumbnail((int) $existing, $attachmentId);
                    echo "Attached image to existing: {$sku}\n";
                }
            }
        }
        continue;
    }

    $name = $row[$col['Name']] ?? $sku;
    $price = (float) ($row[$col['Regular price']] ?? 0);
    $stock = max(1, (int) ($row[$col['Stock']] ?? 1));
    $published = (int) ($row[$col['Published']] ?? 1) === 1;
    $categoryName = $row[$col['Categories']] ?? '';

    $product = new WC_Product_Simple();
    $product->set_name($name);
    $product->set_sku($sku);
    $product->set_status($published ? 'publish' : 'draft');
    $product->set_catalog_visibility('visible');
    $product->set_regular_price((string) $price);
    $product->set_price((string) $price);
    $product->set_manage_stock(true);
    $product->set_stock_quantity($stock);
    $product->set_stock_status('instock');
    $product->set_description($row[$col['Description']] ?? '');
    $product->set_short_description($row[$col['Short description']] ?? '');

    if ($categoryName !== '' && isset($categoryMap[$categoryName])) {
        $product->set_category_ids([$categoryMap[$categoryName]]);
    }

    $productId = $product->save();

    update_post_meta($productId, '_ccr_price_per_day', $row[$col['Meta: _ccr_price_per_day']] ?? $price);
    update_post_meta($productId, '_ccr_is_featured', ($row[$col['Meta: _ccr_is_featured']] ?? '') === 'yes' ? 'yes' : 'no');
    update_post_meta($productId, '_ccr_is_new', ($row[$col['Meta: _ccr_is_new']] ?? '') === 'yes' ? 'yes' : 'no');
    update_post_meta($productId, '_ccr_rating', (int) ($row[$col['Meta: _ccr_rating']] ?? 0));
    update_post_meta($productId, '_ccr_laravel_slug', $row[$col['Meta: _ccr_laravel_slug']] ?? $sku);

    $rentopianId = $row[$col['Meta: _ccr_rentopian_id']] ?? '';
    if ($rentopianId !== '') {
        update_post_meta($productId, '_ccr_rentopian_id', $rentopianId);
    }

    $imageRel = $row[$col['Images']] ?? '';
    if ($imageRel !== '') {
        $attachmentId = attach_product_image($productId, $imageRel, $sku, $imagesTarget, $imagesSource, $imageCache);
        if ($attachmentId) {
            set_post_thumbnail($productId, $attachmentId);
        }
    }

    $imported++;
}

fclose($handle);

// Enable pickup cash gateway
$gateways = (array) get_option('woocommerce_gateway_order', []);
if (! in_array('ccr_pickup_cash', $gateways, true)) {
    $gateways[] = 'ccr_pickup_cash';
    update_option('woocommerce_gateway_order', $gateways);
}
update_option('woocommerce_ccr_pickup_cash_settings', [
    'enabled' => 'yes',
    'title' => 'Pay on pickup (cash)',
    'description' => 'Reserve your gear and pay when you collect it at our Bomso office. Bring a valid Ghana Card.',
]);

// Delete sample content
$hello = get_posts(['post_type' => 'post', 'name' => 'hello-world', 'posts_per_page' => 1]);
foreach ($hello as $post) {
    wp_delete_post($post->ID, true);
}

echo "Products imported: {$imported}, skipped (existing): {$skipped}\n";
echo "Done. Visit http://christocentric-wp.localhost/shop/\n";

function ccr_recursive_copy(string $src, string $dst): void
{
    if (! is_dir($dst)) {
        mkdir($dst, 0777, true);
    }
    $items = scandir($src) ?: [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $from = $src . DIRECTORY_SEPARATOR . $item;
        $to = $dst . DIRECTORY_SEPARATOR . $item;
        if (is_dir($from)) {
            ccr_recursive_copy($from, $to);
        } elseif (! is_file($to)) {
            copy($from, $to);
        }
    }
}

function attach_product_image(int $productId, string $relative, string $sku, string $uploadsChristo, string $laravelImages, array &$cache): ?int
{
    if (isset($cache[$relative])) {
        return $cache[$relative];
    }

    $relative = ltrim(str_replace('\\', '/', $relative), '/');
    $candidates = [
        $uploadsChristo . '/' . str_replace('images/', '', $relative),
        $laravelImages . '/' . str_replace('images/', '', $relative),
        $uploadsChristo . '/storage/products/' . $sku . '.' . pathinfo($relative, PATHINFO_EXTENSION),
    ];

    $source = null;
    foreach ($candidates as $path) {
        if (is_file($path)) {
            $source = $path;
            break;
        }
    }

    if (! $source) {
        return null;
    }

    $upload = wp_upload_bits(basename($source), null, (string) file_get_contents($source));
    if (! empty($upload['error'])) {
        return null;
    }

    $attachment = [
        'post_mime_type' => wp_check_filetype(basename($source))['type'] ?? 'image/jpeg',
        'post_title' => sanitize_file_name(basename($source)),
        'post_content' => '',
        'post_status' => 'inherit',
    ];

    $attachmentId = wp_insert_attachment($attachment, $upload['file'], $productId);
    if (is_wp_error($attachmentId)) {
        return null;
    }

    $meta = wp_generate_attachment_metadata($attachmentId, $upload['file']);
    wp_update_attachment_metadata($attachmentId, $meta);
    $cache[$relative] = (int) $attachmentId;

    return (int) $attachmentId;
}
