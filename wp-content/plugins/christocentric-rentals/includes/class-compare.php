<?php

defined('ABSPATH') || exit;

/**
 * Cookie-based product compare (max 4).
 * Uses a browser cookie so guests keep their list without a WooCommerce cart session.
 */
final class CCR_Compare
{
    public const COOKIE_KEY = 'ccr_compare';
    public const MAX_ITEMS = 4;
    public const ACTION = 'ccr_compare';

    public static function init(): void
    {
        add_action('init', [self::class, 'ensure_page']);
        add_action('admin_post_' . self::ACTION, [self::class, 'handle']);
        add_action('admin_post_nopriv_' . self::ACTION, [self::class, 'handle']);
        add_action('wp_ajax_ccr_compare_toggle', [self::class, 'ajax_toggle']);
        add_action('wp_ajax_nopriv_ccr_compare_toggle', [self::class, 'ajax_toggle']);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_assets']);
    }

    public static function enqueue_assets(): void
    {
        if (! wp_script_is('ccr-theme', 'enqueued') && ! wp_script_is('ccr-theme', 'registered')) {
            return;
        }

        wp_localize_script('ccr-theme', 'ccrCompare', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::ACTION),
            'max' => self::MAX_ITEMS,
            'i18n' => [
                'add' => __('Add to compare', 'christocentric-rentals'),
                'compare' => __('Compare', 'christocentric-rentals'),
                'inCompare' => __('In compare', 'christocentric-rentals'),
                'full' => sprintf(
                    /* translators: %d: max compare items */
                    __('Compare list is full (max %d).', 'christocentric-rentals'),
                    self::MAX_ITEMS
                ),
            ],
        ]);
    }

    public static function ensure_page(): void
    {
        if (get_page_by_path('compare')) {
            return;
        }

        wp_insert_post([
            'post_title' => 'Compare',
            'post_name' => 'compare',
            'post_status' => 'publish',
            'post_type' => 'page',
        ]);
    }

    /**
     * @return list<int>
     */
    public static function ids(): array
    {
        $raw = isset($_COOKIE[self::COOKIE_KEY]) ? (string) wp_unslash($_COOKIE[self::COOKIE_KEY]) : '';
        if ($raw === '') {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map('absint', explode(',', $raw)))));

        return array_slice($ids, 0, self::MAX_ITEMS);
    }

    public static function count(): int
    {
        return count(self::ids());
    }

    public static function has(int $productId): bool
    {
        return in_array(absint($productId), self::ids(), true);
    }

    public static function add(int $productId): bool
    {
        $productId = absint($productId);
        if ($productId <= 0 || ! wc_get_product($productId)) {
            return false;
        }

        $ids = self::ids();
        if (in_array($productId, $ids, true)) {
            return true;
        }
        if (count($ids) >= self::MAX_ITEMS) {
            return false;
        }

        $ids[] = $productId;
        self::persist($ids);

        return true;
    }

    public static function remove(int $productId): void
    {
        $ids = array_values(array_filter(
            self::ids(),
            static fn (int $id): bool => $id !== absint($productId)
        ));
        self::persist($ids);
    }

    public static function clear(): void
    {
        self::persist([]);
    }

    /**
     * @return list<WC_Product>
     */
    public static function products(): array
    {
        $out = [];
        foreach (self::ids() as $id) {
            $product = wc_get_product($id);
            if ($product instanceof WC_Product && $product->get_status() === 'publish') {
                $out[] = $product;
            }
        }

        return $out;
    }

    public static function handle(): void
    {
        if (! isset($_POST['ccr_compare_nonce']) || ! wp_verify_nonce(sanitize_text_field(wp_unslash((string) $_POST['ccr_compare_nonce'])), self::ACTION)) {
            wp_die(esc_html__('Invalid compare request.', 'christocentric-rentals'));
        }

        $op = sanitize_key((string) ($_POST['op'] ?? 'add'));
        $productId = absint($_POST['product_id'] ?? 0);
        $redirect = wp_get_referer() ?: home_url('/compare/');

        if ($op === 'clear') {
            self::clear();
            $redirect = home_url('/compare/');
        } elseif ($op === 'remove' && $productId > 0) {
            self::remove($productId);
        } elseif ($op === 'add' && $productId > 0) {
            self::add($productId);
        }

        wp_safe_redirect($redirect);
        exit;
    }

    public static function ajax_toggle(): void
    {
        check_ajax_referer(self::ACTION, 'nonce');
        $productId = absint($_POST['product_id'] ?? 0);
        $in = self::has($productId);

        if ($in) {
            self::remove($productId);
            $in = false;
            $ok = true;
        } else {
            $ok = self::add($productId);
            $in = $ok;
        }

        wp_send_json([
            'ok' => $ok,
            'in_compare' => $in,
            'count' => self::count(),
            'max' => self::MAX_ITEMS,
            'message' => $ok ? '' : sprintf(
                /* translators: %d: max compare items */
                __('Compare list is full (max %d).', 'christocentric-rentals'),
                self::MAX_ITEMS
            ),
        ]);
    }

    /**
     * @param list<int> $ids
     */
    private static function persist(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('absint', $ids))));
        $ids = array_slice($ids, 0, self::MAX_ITEMS);
        $value = implode(',', $ids);
        $expire = $ids === [] ? time() - HOUR_IN_SECONDS : time() + WEEK_IN_SECONDS;
        $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
        $domain = defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '';

        // Keep PHP in sync for the rest of this request.
        if ($ids === []) {
            unset($_COOKIE[self::COOKIE_KEY]);
        } else {
            $_COOKIE[self::COOKIE_KEY] = $value;
        }

        if (headers_sent()) {
            return;
        }

        setcookie(self::COOKIE_KEY, $value, [
            'expires' => $expire,
            'path' => $path,
            'domain' => $domain,
            'secure' => is_ssl(),
            'httponly' => false,
            'samesite' => 'Lax',
        ]);
    }
}
