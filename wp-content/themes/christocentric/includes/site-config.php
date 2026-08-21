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

    if (class_exists('CCR_Settings')) {
        $contactEmail = CCR_Settings::contact_email();
        if ($key === 'contact' && is_array($value)) {
            $value['email'] = $contactEmail;
            $value['support_email'] = $contactEmail;
            $value['feedback_email'] = $contactEmail;
        } elseif (in_array($key, ['contact.email', 'contact.support_email', 'contact.feedback_email'], true)) {
            $value = $contactEmail;
        }
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

/**
 * Homepage promo hero slides (Absolute Cinema–style).
 *
 * @return list<array{eyebrow:string,title:string,description:string,tagline:string,cta_primary:string,cta_url:string,image:string,image_url:string,theme:string}>
 */
function ccr_hero_slides(): array
{
    $fromConfig = ccr_site_config('hero_slides', []);
    $hasPromoShape = is_array($fromConfig)
        && $fromConfig !== []
        && isset($fromConfig[0]['eyebrow'], $fromConfig[0]['theme']);

    $slides = $hasPromoShape ? $fromConfig : [
        [
            'eyebrow' => 'Absolute Cinema',
            'title' => 'Elevate Your Shots',
            'description' => 'Cutting-edge filmmaking tools and cinema technology',
            'tagline' => 'For filmmakers',
            'cta_primary' => 'Rent Now',
            'cta_url' => '/shop?category=cameras',
            'image' => 'storage/products/sony-pxw-z90-cutout.png',
            'product_slug' => 'sony-pxw-z90',
            'theme' => 'dark',
        ],
        [
            'eyebrow' => 'Continuous Lights',
            'title' => 'Shoot Pro.',
            'description' => 'Gear Up for Your Next Adventure with Premium Camera Rentals',
            'tagline' => 'Enlighten your day',
            'cta_primary' => 'Rent Now',
            'cta_url' => '/shop?category=continuous-light',
            'image' => 'storage/products/amaran-300c-cutout.png',
            'product_slug' => 'amaran-300c',
            'theme' => 'mist',
            'flip' => true,
        ],
        [
            'eyebrow' => 'Gimbals',
            'title' => 'Create Magic',
            'description' => 'Move with ease with our gimbals perfecting your shots',
            'tagline' => 'Stable shots?',
            'cta_primary' => 'Rent Now',
            'cta_url' => '/shop?category=gimbals',
            'image' => 'storage/products/dji-ronin-rs4-cutout.png',
            'product_slug' => 'dji-ronin-rs4',
            'theme' => 'steel',
        ],
        [
            'eyebrow' => 'Featured',
            'title' => 'Capture More',
            'description' => 'Wrap up next-level tech of drones.',
            'tagline' => 'Get your drone shot',
            'cta_primary' => 'Rent Now',
            'cta_url' => '/shop?category=drone',
            'image' => 'storage/products/dji-mavic-3-classic-cutout.png',
            'product_slug' => 'dji-mavic-3-classic',
            'theme' => 'slate',
        ],
    ];

    foreach ($slides as &$slide) {
        $url = '';
        $slug = sanitize_title((string) ($slide['product_slug'] ?? ''));

        // Prefer sharp transparent cutouts so colored carousel backgrounds show through.
        // Skip tiny files (e.g. 225px) that blur when scaled in the hero.
        if ($slug !== '') {
            $cutoutRel = 'storage/products/' . $slug . '-cutout.png';
            $cutoutAbs = WP_CONTENT_DIR . '/uploads/christocentric/' . $cutoutRel;
            if (is_file($cutoutAbs)) {
                $size = @getimagesize($cutoutAbs);
                if (is_array($size) && min((int) $size[0], (int) $size[1]) >= 800) {
                    $url = ccr_image_url($cutoutRel);
                }
            }
        }

        if ($url === '' && ! empty($slide['image'])) {
            $rel = ltrim(str_replace('\\', '/', (string) $slide['image']), '/');
            $abs = WP_CONTENT_DIR . '/uploads/christocentric/' . preg_replace('#^images/#', '', $rel);
            if (is_file($abs)) {
                $url = ccr_image_url($rel);
            }
        }

        if ($url === '' && $slug !== '') {
            $byPath = get_page_by_path($slug, OBJECT, 'product');
            if ($byPath) {
                $product = wc_get_product($byPath->ID);
                if ($product instanceof WC_Product) {
                    $url = (string) (wp_get_attachment_image_url($product->get_image_id(), 'full') ?: '');
                }
            }
        }

        if ($url === '') {
            $url = ccr_image_url((string) ($slide['image'] ?? ''));
        }

        $slide['image_url'] = $url;
    }
    unset($slide);

    return $slides;
}

/**
 * Homepage “Highlighted this week” slides from featured Woo products.
 * Falls back to migration/site-settings.json deals_slides when none are featured.
 *
 * @return list<array{badge:string,title:string,before:string,image:string,image_url:string,url:string}>
 */
function ccr_deals_slides(int $limit = 6): array
{
    $slides = [];

    if (function_exists('wc_get_products')) {
        $products = wc_get_products([
            'limit' => $limit,
            'status' => 'publish',
            'stock_status' => 'instock',
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_key' => '_ccr_is_featured',
            'meta_value' => 'yes',
        ]);

        foreach ($products as $product) {
            if (! $product instanceof WC_Product) {
                continue;
            }

            $terms = get_the_terms($product->get_id(), 'product_cat');
            $badge = ($terms && ! is_wp_error($terms) && isset($terms[0])) ? (string) $terms[0]->name : __('Featured', 'christocentric');

            $before = wp_strip_all_tags((string) $product->get_short_description());
            if ($before === '') {
                $before = wp_strip_all_tags((string) $product->get_description());
            }
            if ($before !== '') {
                $before = wp_trim_words($before, 22, '…');
            }

            $imageUrl = (string) (
                wp_get_attachment_image_url($product->get_image_id(), 'full')
                ?: wp_get_attachment_image_url($product->get_image_id(), 'large')
                ?: wc_placeholder_img_src()
            );

            $slides[] = [
                'badge' => $badge,
                'title' => $product->get_name(),
                'before' => $before,
                'image' => '',
                'image_url' => $imageUrl,
                'url' => get_permalink($product->get_id()) ?: ccr_shop_url(),
            ];
        }
    }

    if ($slides !== []) {
        return $slides;
    }

    $fromConfig = ccr_site_config('deals_slides', []);
    if (! is_array($fromConfig)) {
        return [];
    }

    foreach ($fromConfig as $deal) {
        if (! is_array($deal)) {
            continue;
        }
        $rel = (string) ($deal['image'] ?? '');
        $deal['image_url'] = $rel !== '' ? ccr_image_url($rel) : '';
        $slides[] = $deal;
    }

    return $slides;
}

/**
 * Homepage “Lighting & grip” tiles from Continuous Light products.
 * Falls back to migration featured_lighting when the category is empty.
 *
 * @return list<array{title:string,description:string,image:string,image_url:string,url:string}>
 */
function ccr_featured_lighting(int $limit = 4): array
{
    $items = [];

    if (function_exists('wc_get_products')) {
        $products = wc_get_products([
            'limit' => $limit,
            'status' => 'publish',
            'stock_status' => 'instock',
            'orderby' => 'date',
            'order' => 'DESC',
            'category' => ['continuous-light'],
        ]);

        foreach ($products as $product) {
            if (! $product instanceof WC_Product) {
                continue;
            }

            $description = wp_strip_all_tags((string) $product->get_short_description());
            if ($description === '') {
                $description = wp_strip_all_tags((string) $product->get_description());
            }
            if ($description !== '') {
                $description = wp_trim_words($description, 12, '…');
            } else {
                $description = __('Continuous lighting for sets and events.', 'christocentric');
            }

            $imageUrl = (string) (
                wp_get_attachment_image_url($product->get_image_id(), 'large')
                ?: wp_get_attachment_image_url($product->get_image_id(), 'woocommerce_single')
                ?: wc_placeholder_img_src()
            );

            $items[] = [
                'title' => $product->get_name(),
                'description' => $description,
                'image' => '',
                'image_url' => $imageUrl,
                'url' => get_permalink($product->get_id()) ?: ccr_shop_url(['product_cat' => 'continuous-light']),
            ];
        }
    }

    if ($items !== []) {
        return $items;
    }

    $fromConfig = ccr_site_config('featured_lighting', []);
    if (! is_array($fromConfig)) {
        return [];
    }

    foreach ($fromConfig as $item) {
        if (! is_array($item)) {
            continue;
        }
        $rel = (string) ($item['image'] ?? '');
        $item['image_url'] = $rel !== '' ? ccr_image_url($rel) : '';
        $item['url'] = ccr_shop_url(['product_cat' => 'continuous-light']);
        $items[] = $item;
    }

    return $items;
}

/**
 * Homepage “Browse by category” tiles.
 * Prefer ACF CMS rows; otherwise build from live product categories.
 *
 * @return list<array{title:string,description:string,image:string,image_url:string,url:string}>
 */
function ccr_brand_banners(int $limit = 4): array
{
    $items = [];

    $acfRows = function_exists('ccr_acf_get') ? ccr_acf_get('ccr_brand_banners', []) : [];
    if (is_array($acfRows) && $acfRows !== []) {
        foreach ($acfRows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $term = $row['category'] ?? null;
            $termObj = null;
            if ($term instanceof WP_Term) {
                $termObj = $term;
            } elseif (is_numeric($term)) {
                $maybe = get_term((int) $term, 'product_cat');
                $termObj = $maybe instanceof WP_Term ? $maybe : null;
            }

            $title = trim((string) ($row['title'] ?? ''));
            $description = trim((string) ($row['description'] ?? ''));
            $url = '';
            $imageUrl = '';

            if ($termObj instanceof WP_Term) {
                if ($title === '') {
                    $title = $termObj->name;
                }
                if ($description === '') {
                    $description = sprintf(
                        /* translators: %d: product count */
                        _n('%d rental product', '%d rental products', (int) $termObj->count, 'christocentric'),
                        (int) $termObj->count
                    );
                }
                $link = get_term_link($termObj);
                $url = is_wp_error($link) ? ccr_shop_url(['product_cat' => $termObj->slug]) : (string) $link;
                $imageUrl = ccr_category_thumbnail_url($termObj);
            }

            $img = $row['image'] ?? null;
            if (is_array($img) && ! empty($img['url'])) {
                $imageUrl = (string) $img['url'];
            }

            if ($title === '') {
                continue;
            }

            $items[] = [
                'title' => $title,
                'description' => $description,
                'image' => '',
                'image_url' => $imageUrl !== '' ? $imageUrl : wc_placeholder_img_src(),
                'url' => $url !== '' ? $url : ccr_shop_url(),
            ];
        }

        if ($items !== []) {
            return array_slice($items, 0, $limit);
        }
    }

    $preferred = ['canon-cameras', 'continuous-light', 'drone', 'lens', 'audio-gears', 'gimbals'];
    foreach ($preferred as $slug) {
        if (count($items) >= $limit) {
            break;
        }
        $term = get_term_by('slug', $slug, 'product_cat');
        if (! $term instanceof WP_Term || (int) $term->count < 1) {
            continue;
        }
        $link = get_term_link($term);
        $items[] = [
            'title' => $term->name,
            'description' => sprintf(
                /* translators: %d: product count */
                _n('%d rental product', '%d rental products', (int) $term->count, 'christocentric'),
                (int) $term->count
            ),
            'image' => '',
            'image_url' => ccr_category_thumbnail_url($term) ?: wc_placeholder_img_src(),
            'url' => is_wp_error($link) ? ccr_shop_url(['product_cat' => $term->slug]) : (string) $link,
        ];
    }

    if ($items !== []) {
        return $items;
    }

    $fromConfig = ccr_site_config('brand_banners', []);
    if (! is_array($fromConfig)) {
        return [];
    }

    foreach ($fromConfig as $banner) {
        if (! is_array($banner)) {
            continue;
        }
        $rel = (string) ($banner['image'] ?? '');
        $banner['image_url'] = $rel !== '' ? ccr_image_url($rel) : wc_placeholder_img_src();
        $items[] = $banner;
    }

    return array_slice($items, 0, $limit);
}

function ccr_category_thumbnail_url(WP_Term $term): string
{
    $thumbId = (int) get_term_meta($term->term_id, 'thumbnail_id', true);
    if ($thumbId > 0) {
        $url = wp_get_attachment_image_url($thumbId, 'large');
        if (is_string($url) && $url !== '') {
            return $url;
        }
    }

    $products = wc_get_products([
        'limit' => 1,
        'status' => 'publish',
        'stock_status' => 'instock',
        'category' => [$term->slug],
        'orderby' => 'date',
        'order' => 'DESC',
    ]);

    if ($products === [] || ! ($products[0] instanceof WC_Product)) {
        return '';
    }

    return (string) (
        wp_get_attachment_image_url($products[0]->get_image_id(), 'large')
        ?: ''
    );
}

/**
 * Newsletter settings — ACF overrides migration JSON.
 *
 * @return array<string, mixed>
 */
function ccr_newsletter_config(): array
{
    $base = ccr_site_config('newsletter', []);
    if (! is_array($base)) {
        $base = [];
    }

    $acf = function_exists('ccr_acf_get') ? ccr_acf_get('ccr_newsletter', []) : [];
    if (! is_array($acf)) {
        return $base;
    }

    foreach (['heading', 'subtext', 'button', 'eyebrow'] as $key) {
        if (! empty($acf[$key])) {
            $base[$key] = $acf[$key];
        }
    }

    return $base;
}

/**
 * Trust bar features — ACF overrides migration JSON.
 *
 * @return list<array{title?:string,subtitle?:string}>
 */
function ccr_trust_features(): array
{
    $acf = function_exists('ccr_acf_get') ? ccr_acf_get('ccr_trust_features', []) : [];
    if (is_array($acf) && $acf !== []) {
        return array_values(array_filter($acf, 'is_array'));
    }

    $fromConfig = ccr_site_config('trust_features', []);

    return is_array($fromConfig) ? $fromConfig : [];
}

/**
 * Brand strip logos — ACF rows or theme defaults.
 *
 * @return list<array{name:string,file?:string,image_url?:string}>
 */
function ccr_brand_logos(): array
{
    $acf = function_exists('ccr_acf_get') ? ccr_acf_get('ccr_brand_logos', []) : [];
    $items = [];

    if (is_array($acf)) {
        foreach ($acf as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $img = $row['image'] ?? null;
            $url = is_array($img) && ! empty($img['url']) ? (string) $img['url'] : '';
            $items[] = [
                'name' => $name,
                'image_url' => $url,
            ];
        }
    }

    if ($items !== []) {
        return $items;
    }

    return [
        ['name' => 'Canon', 'file' => 'canon.png'],
        ['name' => 'Sony', 'file' => 'sony.png'],
        ['name' => 'Sigma', 'file' => 'sigma.png'],
        ['name' => 'Godox', 'file' => 'godox.png'],
        ['name' => 'Hollyland', 'file' => 'hollyland.png'],
    ];
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
        'hide_empty' => true,
        'orderby' => 'name',
        'exclude' => [(int) get_option('default_product_cat')],
    ]);

    if (is_wp_error($terms) || $terms === []) {
        return [];
    }

    return array_values(array_map(static fn ($term) => [
        'name' => $term->name,
        'slug' => $term->slug,
        'url' => get_term_link($term),
        'count' => (int) $term->count,
    ], array_filter($terms, static fn ($term) => ! is_wp_error(get_term_link($term)))));
}

function ccr_active_product_cat_slug(): string
{
    if (is_tax('product_cat')) {
        $term = get_queried_object();

        return $term instanceof WP_Term ? $term->slug : '';
    }

    $slug = sanitize_title((string) ($_GET['product_cat'] ?? $_GET['category'] ?? '')); // phpcs:ignore

    return $slug;
}

function ccr_shop_search_query(): string
{
    if (isset($_GET['s'])) { // phpcs:ignore
        return sanitize_text_field(wp_unslash((string) $_GET['s'])); // phpcs:ignore
    }

    return (string) get_search_query();
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

function ccr_product_regular_daily_price(WC_Product $product): float
{
    $daily = get_post_meta($product->get_id(), '_ccr_price_per_day', true);

    if ($daily !== '' && (float) $daily > 0) {
        return (float) $daily;
    }

    $regular = $product->get_regular_price();

    return $regular !== '' ? (float) $regular : 0.0;
}

function ccr_product_sale_daily_price(WC_Product $product): ?float
{
    $sale = get_post_meta($product->get_id(), '_ccr_sale_price_per_day', true);

    if ($sale !== '' && (float) $sale > 0) {
        return (float) $sale;
    }

    $wcSale = $product->get_sale_price();

    if ($wcSale !== '' && (float) $wcSale > 0) {
        return (float) $wcSale;
    }

    return null;
}

function ccr_product_is_on_sale(WC_Product $product): bool
{
    $regular = ccr_product_regular_daily_price($product);
    $effective = ccr_product_daily_price($product);

    return $regular > 0 && $effective < $regular;
}

function ccr_global_discount_percent(): float
{
    $percent = (float) get_option('ccr_global_discount_percent', 0);
    if ($percent <= 0 || $percent >= 100) {
        return 0.0;
    }

    $ends = (string) get_option('ccr_global_discount_ends_at', '');
    if ($ends !== '') {
        try {
            $endsAt = new DateTimeImmutable($ends, wp_timezone());
            if ($endsAt < new DateTimeImmutable('now', wp_timezone())) {
                return 0.0;
            }
        } catch (Exception $e) {
            return 0.0;
        }
    }

    return $percent;
}

function ccr_global_discount_ends_at(): ?DateTimeImmutable
{
    $ends = (string) get_option('ccr_global_discount_ends_at', '');
    if ($ends === '') {
        return null;
    }

    try {
        return new DateTimeImmutable($ends, wp_timezone());
    } catch (Exception $e) {
        return null;
    }
}

/**
 * @return array{title:string,line_1:string,line_2:string,cta:string}|null
 */
function ccr_promo_banner(): ?array
{
    $percent = (int) round(ccr_global_discount_percent());
    if ($percent <= 0) {
        return null;
    }

    $ends = ccr_global_discount_ends_at();
    $endsLabel = $ends ? $ends->format('M j, Y') : '';
    $replace = static function (string $text) use ($percent, $endsLabel): string {
        return str_replace(['{percent}', '{ends}'], [(string) $percent, $endsLabel], $text);
    };

    $title = (string) get_option('ccr_banner_title', 'Gear Sale');
    $line1 = (string) get_option('ccr_banner_line_1', '{percent}% off every rental — cameras, lenses, lights, and more.');
    $line2 = (string) get_option('ccr_banner_line_2', 'Discount applies automatically at checkout. No coupon needed.');
    $cta = (string) get_option('ccr_banner_cta', 'Shop');

    return [
        'title' => $replace($title !== '' ? $title : 'Gear Sale'),
        'line_1' => $replace($line1),
        'line_2' => $replace($line2),
        'cta' => $cta !== '' ? $cta : 'Shop',
    ];
}

function ccr_product_daily_price(WC_Product $product): float
{
    $regular = ccr_product_regular_daily_price($product);
    $price = $regular;

    if (ccr_product_sale_daily_price($product) !== null) {
        $sale = ccr_product_sale_daily_price($product);
        $from = $product->get_date_on_sale_from();
        $to = $product->get_date_on_sale_to();
        $saleActive = true;
        if ($from || $to) {
            $saleActive = $product->is_on_sale();
        }
        if ($saleActive && $sale !== null && $sale > 0 && $sale < $regular) {
            $price = min($price, $sale);
        }
    }

    $globalPercent = ccr_global_discount_percent();
    if ($globalPercent > 0 && $regular > 0) {
        $price = min($price, round($regular * (1 - ($globalPercent / 100)), 2));
    }

    return max(0, $price);
}

function ccr_product_is_new(WC_Product $product): bool
{
    return get_post_meta($product->get_id(), '_ccr_is_new', true) === 'yes';
}

function ccr_product_is_featured(WC_Product $product): bool
{
    return get_post_meta($product->get_id(), '_ccr_is_featured', true) === 'yes';
}

function ccr_product_is_kit(WC_Product $product): bool
{
    return get_post_meta($product->get_id(), '_ccr_is_kit', true) === 'yes'
        || (class_exists('CCR_Product_Kits') && CCR_Product_Kits::is_kit($product->get_id()));
}

function ccr_kits_url(): string
{
    return ccr_shop_url(['ccr_kits' => '1']);
}

/**
 * Ensure product categories used by the Categories nav exist.
 */
function ccr_ensure_nav_categories(): void
{
    if (! taxonomy_exists('product_cat') || get_option('ccr_nav_categories_v2') === 'yes') {
        return;
    }

    $needed = [
        'projectors' => 'Projectors',
        'cameras' => 'Cameras',
        'lens' => 'Lens',
        'canon-cameras' => 'Canon Cameras',
        'canon-lenses' => 'Canon Lenses',
        'sony-cameras' => 'Sony Cameras',
        'sony-lenses' => 'Sony Lenses',
        'sigma-lenses' => 'Sigma Lenses',
        'continuous-light' => 'Continuous Light',
        'strobes' => 'Strobes',
        'flash' => 'Flash',
        'gimbals' => 'Gimbals',
        'drone' => 'Drone',
        'audio-gears' => 'Audio Gears',
        'video-switcher' => 'Video Switcher',
        'transmitter' => 'Transmitter',
        'accessories' => 'Accessories',
        'live-streaming-gears' => 'Live streaming gears',
        'storage' => 'Storage',
        'new-arrivals' => 'New Arrivals',
    ];

    foreach ($needed as $slug => $name) {
        $term = get_term_by('slug', $slug, 'product_cat');
        if ($term instanceof WP_Term) {
            continue;
        }
        wp_insert_term($name, 'product_cat', ['slug' => $slug]);
    }

    update_option('ccr_nav_categories_v2', 'yes');
}

/**
 * Category nav groups for header / mobile / shop sidebar.
 *
 * @return list<array{label:string,items:list<array{label:string,url:string,slug:string,type:string,active:bool}>}>
 */
function ccr_nav_category_groups(): array
{
    $active = ccr_active_product_cat_slug();
    $kitsView = ccr_is_kits_view();
    $shopActive = function_exists('is_shop') && is_shop() && $active === '' && ! $kitsView;

    $resolve = null;
    $resolve = static function (array $item) use (&$resolve, $active, $kitsView, $shopActive): array {
        $type = (string) ($item['type'] ?? 'category');
        $slug = (string) ($item['slug'] ?? '');
        $label = (string) ($item['label'] ?? '');
        $url = ccr_shop_url();
        $isActive = false;
        $children = [];

        if ($type === 'kits') {
            $url = ccr_kits_url();
            $isActive = $kitsView;
        } elseif ($type === 'shop') {
            $url = ccr_shop_url();
            $isActive = $shopActive;
        } elseif ($slug !== '') {
            $term = get_term_by('slug', $slug, 'product_cat');
            if ($term instanceof WP_Term) {
                $link = get_term_link($term, 'product_cat');
                $url = is_wp_error($link) ? ccr_shop_url(['product_cat' => $slug]) : (string) $link;

                $childTerms = get_terms([
                    'taxonomy' => 'product_cat',
                    'parent' => (int) $term->term_id,
                    'hide_empty' => false,
                ]);
                if (! is_wp_error($childTerms)) {
                    foreach ($childTerms as $childTerm) {
                        if (! $childTerm instanceof WP_Term) {
                            continue;
                        }
                        $children[] = $resolve([
                            'label' => $childTerm->name,
                            'slug' => $childTerm->slug,
                        ]);
                    }
                }
            } else {
                $url = ccr_shop_url(['product_cat' => $slug]);
            }
            $isActive = ! $kitsView && $active === $slug;
        }

        if (! empty($item['children']) && is_array($item['children'])) {
            foreach ($item['children'] as $child) {
                if (! is_array($child)) {
                    continue;
                }
                $resolved = $resolve($child);
                $dup = false;
                foreach ($children as $existing) {
                    if (($existing['slug'] ?? '') !== '' && ($existing['slug'] ?? '') === ($resolved['slug'] ?? '')) {
                        $dup = true;
                        break;
                    }
                }
                if (! $dup) {
                    $children[] = $resolved;
                }
            }
        }

        foreach ($children as $child) {
            if (! empty($child['active'])) {
                $isActive = true;
                break;
            }
        }

        return [
            'label' => $label,
            'url' => $url,
            'slug' => $slug,
            'type' => $type,
            'active' => $isActive,
            'children' => $children,
        ];
    };

    $map = [
        [
            'label' => __('Cameras', 'christocentric'),
            'items' => [
                ['label' => __('All cameras', 'christocentric'), 'slug' => 'cameras'],
                ['label' => __('Canon Cameras', 'christocentric'), 'slug' => 'canon-cameras'],
                ['label' => __('Sony Cameras', 'christocentric'), 'slug' => 'sony-cameras'],
            ],
        ],
        [
            'label' => __('Lenses', 'christocentric'),
            'items' => [
                ['label' => __('All lenses', 'christocentric'), 'slug' => 'lens'],
                ['label' => __('Canon Lenses', 'christocentric'), 'slug' => 'canon-lenses'],
                ['label' => __('Sony Lenses', 'christocentric'), 'slug' => 'sony-lenses'],
                ['label' => __('Sigma Lenses', 'christocentric'), 'slug' => 'sigma-lenses'],
            ],
        ],
        [
            'label' => __('Lighting', 'christocentric'),
            'items' => [
                ['label' => __('Continuous Light', 'christocentric'), 'slug' => 'continuous-light'],
                ['label' => __('Strobes', 'christocentric'), 'slug' => 'strobes'],
                ['label' => __('Flash', 'christocentric'), 'slug' => 'flash'],
            ],
        ],
        [
            'label' => __('Audio', 'christocentric'),
            'items' => [
                ['label' => __('Audio Gears', 'christocentric'), 'slug' => 'audio-gears'],
            ],
        ],
        [
            'label' => __('Video & transmission', 'christocentric'),
            'items' => [
                ['label' => __('Video Switcher', 'christocentric'), 'slug' => 'video-switcher'],
                ['label' => __('Transmitter', 'christocentric'), 'slug' => 'transmitter'],
            ],
        ],
        [
            'label' => __('Stabilization & flight', 'christocentric'),
            'items' => [
                ['label' => __('Gimbals', 'christocentric'), 'slug' => 'gimbals'],
                ['label' => __('Drone', 'christocentric'), 'slug' => 'drone'],
            ],
        ],
        [
            'label' => __('Projection', 'christocentric'),
            'items' => [
                ['label' => __('Projectors', 'christocentric'), 'slug' => 'projectors'],
            ],
        ],
        [
            'label' => __('Kits & more', 'christocentric'),
            'items' => [
                ['label' => __('Kits', 'christocentric'), 'type' => 'kits'],
                ['label' => __('Accessories', 'christocentric'), 'slug' => 'accessories'],
                ['label' => __('Live streaming gears', 'christocentric'), 'slug' => 'live-streaming-gears'],
                ['label' => __('Storage', 'christocentric'), 'slug' => 'storage'],
                ['label' => __('New Arrivals', 'christocentric'), 'slug' => 'new-arrivals'],
                ['label' => __('All products', 'christocentric'), 'type' => 'shop'],
            ],
        ],
    ];

    $groups = [];
    foreach ($map as $group) {
        $items = array_map($resolve, $group['items']);
        $groups[] = [
            'label' => $group['label'],
            'items' => $items,
            'active' => (bool) array_filter($items, static fn ($item) => ! empty($item['active'])),
        ];
    }

    return $groups;
}

/**
 * Cookie name for browsing interest (viewed products / categories).
 */
function ccr_interest_cookie_name(): string
{
    return 'ccr_interest';
}

function ccr_cookie_consent_name(): string
{
    return 'ccr_cookie_consent';
}

/**
 * Whether the visitor allowed personalization cookies.
 */
function ccr_cookie_consent_allows_personalization(): bool
{
    $raw = isset($_COOKIE[ccr_cookie_consent_name()])
        ? strtolower(trim((string) wp_unslash($_COOKIE[ccr_cookie_consent_name()]))) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        : '';

    return $raw === 'all' || $raw === '1' || $raw === 'accepted';
}

/**
 * @return array{product_ids:list<int>,category_slugs:list<string>,top_category:string}
 */
function ccr_parse_interest_cookie(): array
{
    $empty = [
        'product_ids' => [],
        'category_slugs' => [],
        'top_category' => '',
    ];

    if (! ccr_cookie_consent_allows_personalization()) {
        return $empty;
    }

    $raw = isset($_COOKIE[ccr_interest_cookie_name()])
        ? (string) wp_unslash($_COOKIE[ccr_interest_cookie_name()]) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
        : '';

    if ($raw === '') {
        return $empty;
    }

    $decoded = json_decode(rawurldecode($raw), true);
    if (! is_array($decoded)) {
        $decoded = json_decode($raw, true);
    }
    if (! is_array($decoded)) {
        return $empty;
    }

    $productIds = [];
    foreach ((array) ($decoded['p'] ?? []) as $id) {
        $id = absint($id);
        if ($id > 0 && ! in_array($id, $productIds, true)) {
            $productIds[] = $id;
        }
        if (count($productIds) >= 12) {
            break;
        }
    }

    $slugs = [];
    foreach ((array) ($decoded['c'] ?? []) as $slug) {
        $slug = sanitize_title((string) $slug);
        if ($slug !== '' && ! in_array($slug, $slugs, true)) {
            $slugs[] = $slug;
        }
        if (count($slugs) >= 8) {
            break;
        }
    }

    return [
        'product_ids' => $productIds,
        'category_slugs' => $slugs,
        'top_category' => $slugs[0] ?? '',
    ];
}

/**
 * Related in-stock products based on browsing interest cookie.
 *
 * @return list<WC_Product>
 */
function ccr_personalized_products(int $limit = 12): array
{
    if (! function_exists('wc_get_products')) {
        return [];
    }

    $interest = ccr_parse_interest_cookie();
    $exclude = $interest['product_ids'];
    $categories = $interest['category_slugs'];

    if ($categories === [] && $exclude === []) {
        return [];
    }

    $limit = max(1, min(24, $limit));
    $found = [];

    if ($categories !== []) {
        $related = ccr_get_products([
            'limit' => $limit + count($exclude),
            'category' => $categories,
            'orderby' => 'date',
            'order' => 'DESC',
            'exclude' => $exclude,
        ]);
        foreach ($related as $product) {
            if (! $product instanceof WC_Product || ! $product->is_purchasable()) {
                continue;
            }
            $found[$product->get_id()] = $product;
            if (count($found) >= $limit) {
                return array_values($found);
            }
        }
    }

    // Soft fill: recently viewed items still in stock (newest interest first).
    if (count($found) < $limit && $exclude !== []) {
        foreach ($exclude as $id) {
            if (isset($found[$id])) {
                continue;
            }
            $product = wc_get_product($id);
            if (! $product instanceof WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock()) {
                continue;
            }
            $found[$id] = $product;
            if (count($found) >= $limit) {
                break;
            }
        }
    }

    return array_values($found);
}

function ccr_personalized_shop_url(): string
{
    $interest = ccr_parse_interest_cookie();
    $slug = $interest['top_category'];

    if ($slug !== '') {
        $term = get_term_by('slug', $slug, 'product_cat');
        if ($term instanceof WP_Term) {
            $link = get_term_link($term, 'product_cat');
            if (! is_wp_error($link)) {
                return (string) $link;
            }
        }

        return ccr_shop_url(['product_cat' => $slug]);
    }

    return ccr_shop_url();
}

function ccr_is_kits_view(): bool
{
    return isset($_GET['ccr_kits']) && (string) wp_unslash($_GET['ccr_kits']) !== '' && (string) wp_unslash($_GET['ccr_kits']) !== '0'; // phpcs:ignore
}

/**
 * Studio booking page config (McB-style wizard).
 *
 * @return array{
 *   whatsapp:string,
 *   eyebrow:string,
 *   title_line_1:string,
 *   title_line_2:string,
 *   title_emphasis:string,
 *   lead:string,
 *   address:string,
 *   stats:list<array{value:string,label:string}>,
 *   studios:list<array{id:string,name:string,blurb:string,meta:string,image:string}>,
 *   durations:list<array{id:string,label:string,hint:string}>,
 *   times:list<string>
 * }
 */
function ccr_studio_booking_config(): array
{
    $contact = ccr_site_config('contact', []);
    $phone = preg_replace('/\D+/', '', (string) ($contact['phone'] ?? '+233532670582')) ?: '233532670582';
    $fromConfig = ccr_site_config('studio_booking', []);
    if (! is_array($fromConfig)) {
        $fromConfig = [];
    }

    $defaults = [
        'whatsapp' => $phone,
        'eyebrow' => 'Kumasi · Bomso · Open daily',
        'title_line_1' => 'Reserve',
        'title_line_2' => 'Your',
        'title_emphasis' => 'Studio',
        'lead' => 'Book a set in our Bomso studio for interviews, portraits, and content shoots — confirmed by WhatsApp within minutes.',
        'address' => trim(($contact['address'] ?? 'Bomso, near Obesse Gaming Center') . ', ' . ($contact['city'] ?? 'Kumasi, Ghana'), ', '),
        'stats' => [
            ['value' => '5', 'label' => 'Sets'],
            ['value' => 'Ready', 'label' => 'Lighting setup'],
            ['value' => 'Full day', 'label' => 'Max session'],
            ['value' => 'Gear', 'label' => 'Can pair rentals'],
        ],
        'studios' => [
            [
                'id' => 'set-1',
                'name' => 'Set 1',
                'blurb' => 'Set 1 in the Bomso studio — book this set, not the whole studio.',
                'meta' => 'Kumasi · Pair with rental gear',
                'image' => '',
            ],
        ],
        'durations' => [
            ['id' => '2h', 'label' => '2 hours', 'hint' => 'Quick interview or headshots'],
            ['id' => '4h', 'label' => '4 hours', 'hint' => 'Half-day content shoot'],
            ['id' => 'full', 'label' => 'Full day', 'hint' => 'Extended production day'],
        ],
        'times' => ['09:00', '10:00', '11:00', '12:00', '13:00', '14:00', '15:00', '16:00'],
    ];

    $merged = array_replace_recursive($defaults, $fromConfig);
    if (empty($merged['whatsapp'])) {
        $merged['whatsapp'] = $phone;
    }
    if (! is_array($merged['stats']) || $merged['stats'] === []) {
        $merged['stats'] = $defaults['stats'];
    }
    if (! is_array($merged['studios']) || $merged['studios'] === []) {
        $merged['studios'] = $defaults['studios'];
    }
    if (! is_array($merged['durations']) || $merged['durations'] === []) {
        $merged['durations'] = $defaults['durations'];
    }
    if (! is_array($merged['times']) || $merged['times'] === []) {
        $merged['times'] = $defaults['times'];
    }

    return $merged;
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
