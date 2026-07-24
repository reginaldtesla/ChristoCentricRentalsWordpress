<?php

defined('ABSPATH') || exit;

function ccr_site_config(string $key, mixed $default = null): mixed
{
    static $config = null;

    if ($config === null) {
        $file = dirname(get_template_directory(), 3) . '/migration/site-settings.json';
        $loaded = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        $config = array_replace_recursive(
            $loaded['laravel_config_defaults'] ?? [],
            $loaded['database_overrides'] ?? []
        );
    }

    $keys = explode('.', $key);
    $value = $config;

    foreach ($keys as $segment) {
        if (! is_array($value) || ! array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

function ccr_image_url(string $path): string
{
    $path = ltrim(str_replace('\\', '/', $path), '/');
    $path = preg_replace('#^images/#', '', $path) ?? $path;

    return content_url('uploads/christocentric/' . $path);
}

function ccr_theme_asset(string $file): string
{
    return get_template_directory_uri() . '/assets/' . ltrim($file, '/');
}

function ccr_format_price(float $amount): string
{
    return ccr_site_config('currency_symbol', '₵') . number_format($amount, 2);
}

function ccr_shop_url(array $args = []): string
{
    $url = wc_get_page_permalink('shop');

    return $args === [] ? $url : add_query_arg($args, $url);
}

/**
 * Convert Laravel-style paths (/shop?category=slug) to WooCommerce URLs.
 */
function ccr_resolve_url(string $path, string $fallback = '/shop/'): string
{
    $path = trim($path);

    if ($path === '') {
        return ccr_resolve_url($fallback, $fallback);
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    if ($path[0] !== '/') {
        $path = '/' . $path;
    }

    $parts = wp_parse_url($path);
    $route = rtrim($parts['path'] ?? '', '/');
    parse_str($parts['query'] ?? '', $query);

    $category = $query['category'] ?? $query['product_cat'] ?? null;

    if ($category !== null && ($route === '/shop' || $route === '')) {
        $slug = sanitize_title((string) $category);
        $term = get_term_by('slug', $slug, 'product_cat');

        if ($term instanceof WP_Term) {
            $link = get_term_link($term, 'product_cat');

            if (! is_wp_error($link)) {
                return $link;
            }
        }

        return ccr_shop_url(['product_cat' => $slug]);
    }

    if (preg_match('#^/shop/([^/?]+)$#', $route, $matches)) {
        $product = get_page_by_path($matches[1], OBJECT, 'product');

        if ($product instanceof WP_Post) {
            return get_permalink($product);
        }
    }

    return home_url($path);
}

function ccr_product_categories(): array
{
    $terms = get_terms([
        'taxonomy' => 'product_cat',
        'hide_empty' => false,
        'orderby' => 'name',
    ]);

    if (is_wp_error($terms)) {
        return [];
    }

    return array_map(static fn ($term) => [
        'name' => $term->name,
        'slug' => $term->slug,
    ], $terms);
}

function ccr_cart_count(): int
{
    if (! function_exists('WC') || ! WC()->cart) {
        return 0;
    }

    return (int) WC()->cart->get_cart_contents_count();
}

function ccr_get_products(array $args = []): array
{
    $defaults = [
        'limit' => 12,
        'status' => 'publish',
        'stock_status' => 'instock',
    ];

    return wc_get_products(array_merge($defaults, $args));
}

function ccr_product_daily_price(WC_Product $product): float
{
    $daily = get_post_meta($product->get_id(), '_ccr_price_per_day', true);

    return $daily !== '' ? (float) $daily : (float) $product->get_regular_price();
}

function ccr_product_is_new(WC_Product $product): bool
{
    return get_post_meta($product->get_id(), '_ccr_is_new', true) === 'yes';
}

function ccr_product_is_featured(WC_Product $product): bool
{
    return get_post_meta($product->get_id(), '_ccr_is_featured', true) === 'yes';
}

function ccr_page_json(string $slug): array
{
    static $cache = [];

    if (isset($cache[$slug])) {
        return $cache[$slug];
    }

    $file = dirname(get_template_directory(), 3) . "/migration/pages/{$slug}.json";
    $cache[$slug] = is_file($file)
        ? (json_decode((string) file_get_contents($file), true) ?: [])
        : [];

    return $cache[$slug];
}

function ccr_page_body_html(string $slug): string
{
    $partial = get_template_directory() . "/template-parts/pages/{$slug}-body.php";

    if (! is_file($partial)) {
        return '';
    }

    ob_start();
    include $partial;

    return trim((string) ob_get_clean());
}
