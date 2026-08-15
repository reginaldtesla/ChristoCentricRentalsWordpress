<?php

defined('ABSPATH') || exit;

/**
 * 301 redirects from old Laravel storefront URLs to WordPress equivalents.
 */
final class CCR_Legacy_Redirects
{
    public static function init(): void
    {
        add_action('template_redirect', [self::class, 'maybe_redirect'], 1);
    }

    public static function maybe_redirect(): void
    {
        if (is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        $path = (string) (wp_parse_url($uri, PHP_URL_PATH) ?: '/');
        $path = untrailingslashit(strtolower($path)) ?: '/';
        $query = [];
        $qs = (string) (wp_parse_url($uri, PHP_URL_QUERY) ?: '');
        if ($qs !== '') {
            parse_str($qs, $query);
        }

        $target = self::map($path, $query);
        if ($target === null) {
            return;
        }

        // Avoid redirect loops when already on the destination.
        $current = untrailingslashit(strtolower((string) (wp_parse_url(home_url(add_query_arg([])), PHP_URL_PATH) ?: '/'))) ?: '/';
        $destPath = untrailingslashit(strtolower((string) (wp_parse_url($target, PHP_URL_PATH) ?: '/'))) ?: '/';
        if ($current === $destPath && empty(array_diff_assoc($query, []))) {
            // Allow query-only remaps (e.g. category= → product_cat=).
            $destQuery = [];
            $destQs = (string) (wp_parse_url($target, PHP_URL_QUERY) ?: '');
            if ($destQs !== '') {
                parse_str($destQs, $destQuery);
            }
            if ($query == $destQuery) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
                return;
            }
        }

        wp_safe_redirect($target, 301);
        exit;
    }

    /**
     * @param array<string, mixed> $query
     */
    private static function map(string $path, array $query): ?string
    {
        // /shop/{slug} product pages (not /shop alone).
        if (preg_match('#^/shop/([^/]+)$#', $path, $m)) {
            $slug = sanitize_title($m[1]);
            $product = get_page_by_path($slug, OBJECT, 'product');
            if ($product) {
                return get_permalink($product);
            }
            // Fallback to Woo product URL pattern.
            return home_url('/product/' . $slug . '/');
        }

        if ($path === '/shop' && ! empty($query['category'])) {
            $cat = sanitize_title((string) $query['category']);
            unset($query['category']);
            $term = get_term_by('slug', $cat, 'product_cat');
            if ($term && ! is_wp_error($term)) {
                $link = get_term_link($term);
                if (! is_wp_error($link)) {
                    return $query === [] ? $link : add_query_arg($query, $link);
                }
            }
            $query['product_cat'] = $cat;

            return add_query_arg($query, wc_get_page_permalink('shop') ?: home_url('/shop/'));
        }

        if ($path === '/kits') {
            return function_exists('ccr_kits_url') ? ccr_kits_url() : add_query_arg('ccr_kits', '1', home_url('/shop/'));
        }

        if (preg_match('#^/kits/([^/]+)$#', $path, $m)) {
            $product = get_page_by_path(sanitize_title($m[1]), OBJECT, 'product');

            return $product ? get_permalink($product) : home_url('/product/' . sanitize_title($m[1]) . '/');
        }

        $simple = [
            '/login' => 'myaccount',
            '/register' => 'myaccount',
            '/account' => 'myaccount',
            '/account/profile' => 'edit-account',
            '/account/orders' => 'orders',
            '/cart' => 'cart',
            '/checkout' => 'checkout',
        ];

        if (isset($simple[$path])) {
            $key = $simple[$path];
            if ($key === 'cart') {
                return wc_get_cart_url();
            }
            if ($key === 'checkout') {
                return wc_get_checkout_url();
            }
            if ($key === 'myaccount') {
                return wc_get_page_permalink('myaccount');
            }
            if (function_exists('wc_get_account_endpoint_url')) {
                return wc_get_account_endpoint_url($key);
            }
        }

        if (preg_match('#^/account/orders/(\d+)$#', $path, $m)) {
            return wc_get_endpoint_url('view-order', $m[1], wc_get_page_permalink('myaccount'));
        }

        if (preg_match('#^/checkout/#', $path)) {
            return wc_get_checkout_url();
        }

        return null;
    }
}
