<?php

defined('ABSPATH') || exit;

require_once get_template_directory() . '/includes/site-config.php';
require_once get_template_directory() . '/includes/account-helpers.php';
require_once get_template_directory() . '/includes/acf-homepage.php';

add_action('after_setup_theme', static function (): void {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('woocommerce');
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script']);

    register_nav_menus([
        'primary' => __('Primary Menu', 'christocentric'),
    ]);
});

/**
 * Use the public-site favicon in wp-admin and on the login screen
 * when no Customizer Site Icon is set.
 */
add_filter('get_site_icon_url', static function (string $url): string {
    if ($url !== '') {
        return $url;
    }

    $png = get_template_directory() . '/assets/images/brand/icon.png';
    if (is_readable($png)) {
        return ccr_theme_asset('images/brand/icon.png');
    }

    $ico = get_template_directory() . '/assets/favicon.ico';
    if (is_readable($ico)) {
        return ccr_theme_asset('favicon.ico');
    }

    return $url;
});

add_action('init', static function (): void {
    if (function_exists('ccr_ensure_nav_categories')) {
        ccr_ensure_nav_categories();
    }
}, 20);

add_filter('woocommerce_enqueue_styles', '__return_empty_array');

/**
 * Customer-facing WooCommerce copy: “shipping” → “delivery” (Ghana).
 */
add_filter('woocommerce_shipping_package_name', static function (): string {
    return __('Delivery', 'christocentric');
});

add_filter('gettext', static function (string $translated, string $text, string $domain): string {
    if ($domain !== 'woocommerce') {
        return $translated;
    }
    if (is_admin() && ! wp_doing_ajax()) {
        return $translated;
    }

    $map = [
        'Shipping' => 'Delivery',
        'Shipping address' => 'Delivery address',
        'Shipping Address' => 'Delivery address',
        'Ship to a different address?' => 'Deliver to a different address?',
        'Ship to a different address' => 'Deliver to a different address',
        'Shipping options' => 'Delivery options',
        'Shipping method' => 'Delivery method',
        'Shipping methods' => 'Delivery methods',
        'Calculate shipping' => 'Calculate delivery',
        'Update totals' => 'Update totals',
        'Change address' => 'Change address',
        'Enter your address to view shipping options.' => 'Enter your address to view delivery options.',
        'Shipping costs updated.' => 'Delivery costs updated.',
        'Free shipping' => 'Free delivery',
        'Free shipping on orders over %s' => 'Free delivery on orders over %s',
        'No shipping options were found for %s.' => 'No delivery options were found for %s.',
        'There are no shipping options available. Please ensure that your address has been entered correctly, or contact us if you need any help.' => 'There are no delivery options available. Please ensure that your address has been entered correctly, or contact us if you need any help.',
        'Enter a different address' => 'Enter a different address',
        'Shipping to %s.' => 'Delivery to %s.',
        'Shipping to %s' => 'Delivery to %s',
        'Cash on delivery' => 'Cash on pickup',
        'Pay with cash upon delivery.' => 'Pay with cash at pickup.',
        'Payment to be made upon delivery.' => 'Payment to be made at pickup.',
        'Let your shoppers pay upon delivery — by cash or other methods of payment.' => 'Let your shoppers pay at pickup — by cash or other methods of payment.',
        'via %s' => 'via %s',
        'Customer provided note:' => 'Customer provided note:',
    ];

    if (isset($map[$text])) {
        return $map[$text];
    }
    if (isset($map[$translated])) {
        return $map[$translated];
    }

    return $translated;
}, 20, 3);

add_filter('ngettext', static function (string $translation, string $single, string $plural, int $number, string $domain): string {
    if ($domain !== 'woocommerce') {
        return $translation;
    }
    if (is_admin() && ! wp_doing_ajax()) {
        return $translation;
    }
    if ($single === 'Shipping' || $single === 'Shipping %d') {
        return $number === 1 ? 'Delivery' : sprintf('Delivery %d', $number);
    }

    return $translation;
}, 20, 5);

add_filter('woocommerce_gateway_title', static function (string $title, string $id): string {
    if ($id === 'cod' && stripos($title, 'cash on delivery') !== false) {
        return __('Cash on pickup', 'christocentric');
    }

    return $title;
}, 20, 2);

add_filter('woocommerce_gateway_description', static function (string $description, $id = ''): string {
    if ((string) $id !== 'cod') {
        return $description;
    }

    return str_ireplace(
        ['upon delivery', 'on delivery', 'Cash on delivery'],
        ['at pickup', 'on pickup', 'Cash on pickup'],
        $description
    );
}, 20, 2);

add_action('wp_enqueue_scripts', static function (): void {
    $uri = get_template_directory_uri() . '/assets/build/';
    $ver = '3.84';

    wp_dequeue_style('wc-blocks-style');
    wp_dequeue_style('wc-blocks-vendors-style');
    wp_dequeue_style('wp-block-library');
    wp_dequeue_style('wp-block-library-theme');
    wp_dequeue_style('classic-theme-styles');
    wp_dequeue_style('global-styles');

    wp_enqueue_style('ccr-fonts', $uri . 'fonts-fixed.css', [], $ver);
    wp_enqueue_style('ccr-app', $uri . 'app-DmKbprn4.css', ['ccr-fonts'], $ver);
    wp_enqueue_style('ccr-overrides', get_template_directory_uri() . '/assets/theme-overrides.css', ['ccr-app'], $ver);
    wp_enqueue_script('ccr-app', $uri . 'app-C_fcRPYi.js', [], $ver, true);
    wp_enqueue_script('ccr-theme', get_template_directory_uri() . '/assets/theme.js', ['ccr-app'], $ver, true);
    wp_localize_script('ccr-theme', 'ccrCompare', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ccr_compare'),
        'max' => 4,
        'i18n' => [
            'add' => __('Add to compare', 'christocentric'),
            'compare' => __('Compare', 'christocentric'),
            'inCompare' => __('In compare', 'christocentric'),
            'full' => __('Compare list is full (max 4).', 'christocentric'),
        ],
    ]);
    wp_localize_script('ccr-theme', 'ccrQuickAdd', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ccr_quick_add'),
        'i18n' => [
            'add' => __('Add', 'christocentric'),
            'adding' => __('Adding…', 'christocentric'),
            'added' => __('Added', 'christocentric'),
            'error' => __('Could not add to cart.', 'christocentric'),
        ],
    ]);

    $interestPayload = [
        'cookie' => 'ccr_interest',
        'consentCookie' => 'ccr_cookie_consent',
        'maxAge' => 30 * DAY_IN_SECONDS,
        'consentMaxAge' => 365 * DAY_IN_SECONDS,
        'productId' => 0,
        'categories' => [],
        'privacyUrl' => home_url('/privacy/'),
    ];
    if (function_exists('is_product') && is_product()) {
        $product = wc_get_product(get_queried_object_id());
        if ($product instanceof WC_Product) {
            $interestPayload['productId'] = (int) $product->get_id();
            $terms = get_the_terms($product->get_id(), 'product_cat');
            if (is_array($terms)) {
                foreach ($terms as $term) {
                    if ($term instanceof WP_Term && $term->slug !== '') {
                        $interestPayload['categories'][] = $term->slug;
                    }
                }
            }
        }
    }
    wp_localize_script('ccr-theme', 'ccrInterest', $interestPayload);

    if ((is_page('studio') || (function_exists('ccr_is_studio_subdomain') && ccr_is_studio_subdomain())) && class_exists('CCR_Studio_Booking')) {
        wp_localize_script('ccr-theme', 'ccrStudio', CCR_Studio_Booking::frontend_payload());
    }
}, 100);

// WC Blocks may re-enqueue after priority 100 — strip again before print.
add_action('wp_print_styles', static function (): void {
    wp_dequeue_style('wc-blocks-style');
    wp_dequeue_style('wc-blocks-vendors-style');
    wp_dequeue_style('wp-block-library');
    wp_dequeue_style('wp-block-library-theme');
    wp_dequeue_style('classic-theme-styles');
    wp_dequeue_style('global-styles');
}, 100);

add_filter('body_class', static function (array $classes): array {
    $classes[] = 'ccr-theme';
    if (is_page('studio') || (function_exists('ccr_is_studio_subdomain') && ccr_is_studio_subdomain())) {
        $classes[] = 'ccr-studio-booking';
    }

    return $classes;
});

// Remove default WooCommerce wrappers — theme provides layout.
remove_action('woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10);
remove_action('woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10);
remove_action('woocommerce_sidebar', 'woocommerce_get_sidebar', 10);

add_action('woocommerce_before_main_content', static function (): void {
    echo '<div class="ccr-woocommerce-main">';
}, 5);
add_action('woocommerce_after_main_content', static function (): void {
    echo '</div>';
}, 50);

// Single product: custom summary in woocommerce/single-product.php template.
remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_title', 5);
remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_rating', 10);
remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_price', 10);
remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_excerpt', 20);
remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40);
remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_sharing', 50);

add_filter('woocommerce_product_loop_start', static function (string $html): string {
    return '<ul class="products grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-4 lg:gap-6 list-none p-0 m-0">';
});

add_action('woocommerce_product_query', static function (WP_Query $query): void {
    if (isset($_GET['product_cat']) || ! isset($_GET['category'])) { // phpcs:ignore
        // continue to search handling below
    } else {
        $slug = sanitize_title(wp_unslash((string) $_GET['category'])); // phpcs:ignore

        if ($slug !== '') {
            $taxQuery = (array) $query->get('tax_query');
            $taxQuery[] = [
                'taxonomy' => 'product_cat',
                'field' => 'slug',
                'terms' => $slug,
            ];
            $query->set('tax_query', $taxQuery);
        }
    }

    if (! empty($_GET['s'])) { // phpcs:ignore
        $query->set('s', sanitize_text_field(wp_unslash((string) $_GET['s']))); // phpcs:ignore
    }

    // Shop filter: rental kits only.
    if (function_exists('ccr_is_kits_view') && ccr_is_kits_view()) {
        $metaQuery = (array) $query->get('meta_query');
        $metaQuery[] = [
            'key' => '_ccr_is_kit',
            'value' => 'yes',
        ];
        $query->set('meta_query', $metaQuery);
    }
}, 20);

add_action('woocommerce_before_cart', static function (): void {
    get_template_part('template-parts/page-hero', null, [
        'title' => 'Your Cart',
        'subtitle' => 'Review your rental items before checkout.',
    ]);
    echo '<div class="container-site py-10">';
}, 5);

add_action('woocommerce_after_cart', static function (): void {
    echo '</div>';
}, 50);

add_action('woocommerce_before_checkout_form', static function (): void {
    get_template_part('template-parts/page-hero', null, [
        'title' => 'Checkout',
        'subtitle' => 'Complete your rental booking.',
    ]);
    echo '<div class="container-site py-10">';
}, 5);

add_action('woocommerce_after_checkout_form', static function (): void {
    echo '</div>';
}, 50);

add_action('woocommerce_review_order_before_payment', static function (): void {
    $contact = ccr_site_config('contact', []);
    $address = trim(($contact['address'] ?? '') . ', ' . ($contact['city'] ?? ''), ', ');
    if ($address === '') {
        return;
    }
    echo '<div class="mb-4 rounded-lg bg-primary-light p-4 text-sm text-gray-700">';
    echo '<p class="font-semibold">' . esc_html__('Pickup policy', 'christocentric') . '</p>';
    echo '<p class="mt-1">' . esc_html(sprintf(__('First-time clients must pick up in person with valid Ghana Card at %s.', 'christocentric'), $address)) . '</p>';
    echo '</div>';
}, 5);

add_filter('woocommerce_account_menu_items', static function (array $items): array {
    unset($items['dashboard'], $items['downloads'], $items['edit-address'], $items['customer-logout']);

    if (isset($items['orders'])) {
        $items['orders'] = __('Orders', 'christocentric');
    }

    if (isset($items['edit-account'])) {
        $items['edit-account'] = __('Profile', 'christocentric');
    }

    return $items;
}, 20);

function ccr_remember_paid_order(int $orderId): void
{
    if ($orderId <= 0 || ! function_exists('WC') || ! WC()->session) {
        return;
    }
    WC()->session->set('ccr_last_paid_order_id', $orderId);
    WC()->session->set('ccr_last_paid_order_at', time());
}

function ccr_recent_paid_order_for_redirect(): ?WC_Order
{
    if (! function_exists('WC')) {
        return null;
    }

    $orderId = 0;
    $paidAt = 0;
    if (WC()->session) {
        $orderId = (int) WC()->session->get('ccr_last_paid_order_id');
        $paidAt = (int) WC()->session->get('ccr_last_paid_order_at');
    }

    if ($orderId <= 0 && is_user_logged_in()) {
        $orders = wc_get_orders([
            'customer_id' => get_current_user_id(),
            'limit' => 1,
            'status' => ['processing', 'completed', 'on-hold'],
            'orderby' => 'date',
            'order' => 'DESC',
        ]);
        $candidate = $orders[0] ?? null;
        if ($candidate instanceof WC_Order) {
            $orderId = $candidate->get_id();
            $created = $candidate->get_date_created();
            $paidAt = $created ? $created->getTimestamp() : 0;
        }
    }

    if ($orderId <= 0 || $paidAt < time() - (2 * HOUR_IN_SECONDS)) {
        return null;
    }

    $order = wc_get_order($orderId);

    return $order instanceof WC_Order ? $order : null;
}

add_action('woocommerce_payment_complete', static function ($orderId): void {
    ccr_remember_paid_order((int) $orderId);
}, 5);

add_action('woocommerce_checkout_order_processed', static function ($orderId): void {
    ccr_remember_paid_order((int) $orderId);
}, 20);

function ccr_orders_page_url(?WC_Order $order = null): string
{
    if (function_exists('wc_get_account_endpoint_url')) {
        return wc_get_account_endpoint_url('orders');
    }

    return $order instanceof WC_Order ? $order->get_checkout_order_received_url() : home_url('/my-account/orders/');
}

add_filter('woocommerce_get_return_url', static function (string $url, $order = null): string {
    if (! $order instanceof WC_Order) {
        return $url;
    }
    if ((string) $order->get_meta('_ccr_is_studio_booking') === '1') {
        return $url;
    }
    if ($order->has_status('failed')) {
        return $url;
    }
    ccr_remember_paid_order($order->get_id());
    if (function_exists('wc_add_notice')) {
        wc_add_notice(
            sprintf(
                /* translators: %s: order number */
                __('Order #%s is confirmed. You can view it below.', 'christocentric'),
                $order->get_order_number()
            ),
            'success'
        );
    }

    return ccr_orders_page_url($order);
}, 30, 2);

add_action('template_redirect', static function (): void {
    if (! function_exists('is_order_received_page') || ! is_order_received_page()) {
        return;
    }

    global $wp;
    $orderId = absint($wp->query_vars['order-received'] ?? 0);
    $order = $orderId > 0 ? wc_get_order($orderId) : null;
    if (! $order instanceof WC_Order) {
        return;
    }
    if ($order->has_status('failed')) {
        return;
    }
    if ((string) $order->get_meta('_ccr_is_studio_booking') === '1') {
        return;
    }

    wp_safe_redirect(ccr_orders_page_url($order));
    exit;
}, 6);

add_action('template_redirect', static function (): void {
    if (! function_exists('is_cart') || ! is_cart() || is_checkout()) {
        return;
    }
    if (! WC()->cart || ! WC()->cart->is_empty()) {
        return;
    }

    $order = ccr_recent_paid_order_for_redirect();
    if (! $order instanceof WC_Order) {
        return;
    }

    $target = ccr_orders_page_url($order);
    if ((string) $order->get_meta('_ccr_is_studio_booking') === '1' && class_exists('CCR_Studio_Booking')) {
        $target = CCR_Studio_Booking::return_url($order->get_checkout_order_received_url(), $order);
    }

    wp_safe_redirect($target);
    exit;
}, 8);

add_action('template_redirect', static function (): void {
    if (! is_account_page() || ! is_user_logged_in() || is_wc_endpoint_url()) {
        return;
    }

    $endpoint = function_exists('WC') && WC()->query
        ? (string) WC()->query->get_current_endpoint()
        : '';

    if ($endpoint !== '') {
        return;
    }

    wp_safe_redirect(wc_get_account_endpoint_url('orders'));
    exit;
});

add_action('woocommerce_before_edit_account_form', static function (): void {
    echo '<div class="ccr-account-panel rounded-2xl border border-gray-200 bg-white p-6">';
}, 5);

add_action('woocommerce_after_edit_account_form', static function (): void {
    echo '</div>';
}, 50);

add_action('after_switch_theme', static function (): void {
    $home_id = wp_insert_post([
        'post_title' => 'Home',
        'post_name' => 'home',
        'post_status' => 'publish',
        'post_type' => 'page',
    ], true);

    if (! is_wp_error($home_id)) {
        update_option('show_on_front', 'page');
        update_option('page_on_front', (int) $home_id);
    }

    $pages = [
        'about' => 'About',
        'contact' => 'Contact',
        'faq' => 'FAQ',
        'help' => 'Help',
        'terms' => 'Terms',
        'privacy' => 'Privacy',
        'studio' => 'Studio',
        'compare' => 'Compare',
    ];
    foreach ($pages as $slug => $title) {
        if (! get_page_by_path($slug)) {
            wp_insert_post([
                'post_title' => $title,
                'post_name' => $slug,
                'post_status' => 'publish',
                'post_type' => 'page',
            ]);
        }
    }

    flush_rewrite_rules();
});

// Keep product images at maximum quality — use originals, don't downscale uploads.
add_filter('jpeg_quality', static fn (): int => 100);
add_filter('wp_editor_set_quality', static fn (): int => 100);
add_filter('big_image_size_threshold', '__return_false');

add_filter('woocommerce_get_image_size_single', static function (): array {
    return [
        'width' => 2000,
        'height' => 2000,
        'crop' => 0,
    ];
});

add_filter('woocommerce_get_image_size_thumbnail', static function (): array {
    return [
        'width' => 800,
        'height' => 800,
        'crop' => 0,
    ];
});

add_filter('woocommerce_get_image_size_gallery_thumbnail', static function (): array {
    return [
        'width' => 300,
        'height' => 300,
        'crop' => 0,
    ];
});

// Shop / catalog performance defaults.
add_filter('loop_shop_per_page', static fn (): int => 12, 20);

add_action('init', static function (): void {
    if (get_option('ccr_plain_login_v1') === 'yes') {
        return;
    }
    update_option('woocommerce_registration_generate_password', 'no');
    update_option('ccr_plain_login_v1', 'yes');
});

add_action('wp_head', static function (): void {
    if (is_admin()) {
        return;
    }
    echo '<link rel="dns-prefetch" href="//fonts.googleapis.com">' . "\n";
    echo '<meta name="theme-color" content="#0f172a">' . "\n";
}, 1);

// Soft caching headers for anonymous catalog browsing (hosts/CDN may honor these).
add_action('send_headers', static function (): void {
    if (is_admin() || is_user_logged_in() || is_cart() || is_checkout() || is_account_page()) {
        return;
    }
    if (is_front_page() || is_shop() || is_product_taxonomy() || is_product()) {
        header('Cache-Control: public, max-age=300, s-maxage=600', false);
    }
});
