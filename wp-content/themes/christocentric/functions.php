<?php

defined('ABSPATH') || exit;

require_once get_template_directory() . '/includes/site-config.php';
require_once get_template_directory() . '/includes/account-helpers.php';

add_action('after_setup_theme', static function (): void {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('woocommerce');
    add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script']);

    register_nav_menus([
        'primary' => __('Primary Menu', 'christocentric'),
    ]);
});

add_filter('woocommerce_enqueue_styles', '__return_empty_array');

add_action('wp_enqueue_scripts', static function (): void {
    $uri = get_template_directory_uri() . '/assets/build/';
    $ver = '1.7';

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
}, 100);

add_filter('body_class', static function (array $classes): array {
    $classes[] = 'ccr-theme';

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
        return;
    }

    $slug = sanitize_title(wp_unslash((string) $_GET['category'])); // phpcs:ignore

    if ($slug === '') {
        return;
    }

    $taxQuery = (array) $query->get('tax_query');
    $taxQuery[] = [
        'taxonomy' => 'product_cat',
        'field' => 'slug',
        'terms' => $slug,
    ];
    $query->set('tax_query', $taxQuery);
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

    foreach (['about', 'contact', 'faq', 'help', 'terms', 'privacy'] as $slug) {
        if (! get_page_by_path($slug)) {
            wp_insert_post([
                'post_title' => ucfirst($slug),
                'post_name' => $slug,
                'post_status' => 'publish',
                'post_type' => 'page',
            ]);
        }
    }

    flush_rewrite_rules();
});
