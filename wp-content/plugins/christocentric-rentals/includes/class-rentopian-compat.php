<?php

defined('ABSPATH') || exit;

/**
 * Keeps Christocentric Rentals features working alongside the official Rentopian Sync plugin.
 */
final class CCR_Rentopian_Compat
{
    public static function init(): void
    {
        if (! self::is_active()) {
            return;
        }

        add_action('admin_notices', [self::class, 'admin_notices']);
        add_action('admin_notices', [self::class, 'inventory_mapping_notice']);
        add_action('wp', [self::class, 'detach_ccr_rental_ui'], 20);
        add_action('wp_enqueue_scripts', [self::class, 'dequeue_ccr_rental_assets'], 100);
        add_action('wp_enqueue_scripts', [self::class, 'enqueue_period_assets'], 110);
        add_filter('body_class', [self::class, 'body_class']);

        // Pickup-first site: persist Rentopian "Hide ZIP" so availability checks
        // and date saves use the same path (empty zip), not a fake ZIP that
        // makes every product look unavailable.
        add_action('init', [self::class, 'ensure_hide_zip_for_pickup'], 5);

        // Custom product template omits woocommerce_before_single_product — mount on add-to-cart form instead.
        add_action('woocommerce_before_add_to_cart_form', [self::class, 'render_rental_period_ui'], 5);

        // Before Rentopian's generic “not available”, explain missing inventory mapping.
        add_filter('woocommerce_add_to_cart_validation', [self::class, 'validate_inventory_mapping'], 5, 5);
    }

    /**
     * Fail early with a clear message when a product has no Rentopian inventory ID.
     *
     * @param bool $passed
     * @param int  $product_id
     * @param int  $quantity
     * @param int  $variation_id
     * @param array|null $variations
     */
    public static function validate_inventory_mapping($passed, $product_id, $quantity, $variation_id = 0, $variations = null)
    {
        if (! $passed) {
            return $passed;
        }

        $lookup = (int) ($variation_id ?: $product_id);
        $inv = (int) get_post_meta($lookup, '_rental_inventory_id', true);
        if ($inv > 0) {
            return $passed;
        }

        // Variable parent may only store IDs on children — require a variation choice.
        $product = wc_get_product((int) $product_id);
        if ($product && $product->is_type('variable') && ! $variation_id) {
            return $passed;
        }

        wc_add_notice(
            __('This product is not linked to Rentopian inventory yet. An admin must run Rentopian Sync on this live site before it can be booked.', 'christocentric-rentals'),
            'error'
        );

        return false;
    }

    /**
     * Turn on Rentopian Hide ZIP once for pickup-only bookings.
     */
    public static function ensure_hide_zip_for_pickup(): void
    {
        if (! apply_filters('ccr_rentopian_force_pickup_no_zip', true)) {
            return;
        }

        if ((int) get_option('rental_hide_zip', 0) === 1) {
            return;
        }

        update_option('rental_hide_zip', 1);
    }

    public static function is_active(): bool
    {
        if (defined('RENTOPIAN_SYNC_VERSION')) {
            return true;
        }

        if (function_exists('rental_check_availability')) {
            return true;
        }

        if (! function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active('rentopian-sync/rentopian-sync.php');
    }

    public static function detach_ccr_rental_ui(): void
    {
        remove_action('woocommerce_before_add_to_cart_button', ['CCR_Rental_Cart', 'render_product_fields']);

        // Dates belong on the product page only — not shop/category listings.
        remove_action('woocommerce_before_shop_loop', 'rental_create_date_form', 10);
        remove_action('woocommerce_before_single_product', 'rental_create_date_form', 10);
    }

    public static function dequeue_ccr_rental_assets(): void
    {
        wp_dequeue_script('ccr-rental');
        wp_deregister_script('ccr-rental');
    }

    public static function enqueue_period_assets(): void
    {
        if (! self::should_render_period_ui()) {
            return;
        }

        if (! is_product()) {
            return;
        }

        wp_enqueue_script(
            'ccr-rentopian-period',
            CCR_PLUGIN_URL . 'assets/rentopian-period.js',
            ['jquery'],
            CCR_VERSION,
            true
        );

        wp_localize_script('ccr-rentopian-period', 'ccrRentopianPeriod', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
        ]);
    }

    /**
     * @param list<string> $classes
     * @return list<string>
     */
    public static function body_class(array $classes): array
    {
        $classes[] = 'ccr-rentopian-rental-flow';

        return $classes;
    }

    /**
     * Rentopian Sync owns product-page dates, cart pricing, and checkout dates.
     */
    public static function defers_rental_flow(): bool
    {
        return self::is_active();
    }

    public static function should_render_period_ui(): bool
    {
        if (get_option('rental_synchronized_product_type') === 'hourly') {
            return false;
        }

        if (get_option('rental_form_layout') === 'in-cart') {
            return false;
        }

        if (
            (int) get_option('rental_dates_on_checkout', 0) === 1
            && (int) get_option('rental_allow_overbook', 1) === 1
        ) {
            return false;
        }

        return true;
    }

    /**
     * Christocentric-styled rental period fields that save dates through Rentopian's API.
     */
    public static function render_rental_period_ui(): void
    {
        if (! self::should_render_period_ui()) {
            return;
        }

        static $rendered = false;
        if ($rendered) {
            return;
        }
        $rendered = true;

        $hideEnd = (bool) get_option('rental_hide_end_date');
        $hideZip = true; // Pickup-first UI — ZIP not collected on product pages.
        $zip = '1';
        $defaults = self::default_period_values();
        $hoursLabel = (string) apply_filters(
            'ccr_rentopian_hours_label',
            __('Open 7:00 am – 9:00 pm daily', 'christocentric-rentals')
        );
        ?>
        <div
            class="ccr-rental-period"
            data-ccr-rental-period
            data-return-auto="1"
            data-hide-end="<?php echo $hideEnd ? '1' : '0'; ?>"
            data-hide-zip="<?php echo $hideZip ? '1' : '0'; ?>"
            data-zip="<?php echo esc_attr($zip); ?>"
        >
            <p class="ccr-rental-period-title"><?php esc_html_e('Rental period', 'christocentric-rentals'); ?></p>

            <div class="ccr-rental-period-grid">
                <div class="ccr-rental-period-field">
                    <label class="ccr-rental-period-label" for="ccr_rntp_pickup_date"><?php esc_html_e('Pickup date', 'christocentric-rentals'); ?></label>
                    <div class="ccr-rental-period-control">
                        <input
                            type="date"
                            id="ccr_rntp_pickup_date"
                            class="ccr-rental-period-input"
                            value="<?php echo esc_attr($defaults['start_date']); ?>"
                            min="<?php echo esc_attr($defaults['today']); ?>"
                            data-ccr-pickup-date
                            required
                        >
                        <span class="ccr-rental-period-icon" aria-hidden="true">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        </span>
                    </div>
                </div>

                <div class="ccr-rental-period-field">
                    <label class="ccr-rental-period-label" for="ccr_rntp_pickup_time"><?php esc_html_e('Pickup time', 'christocentric-rentals'); ?></label>
                    <div class="ccr-rental-period-control">
                        <input
                            type="time"
                            id="ccr_rntp_pickup_time"
                            class="ccr-rental-period-input"
                            value="<?php echo esc_attr($defaults['start_time']); ?>"
                            step="900"
                            data-ccr-pickup-time
                            required
                        >
                        <span class="ccr-rental-period-icon" aria-hidden="true">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </span>
                    </div>
                </div>

                <?php if (! $hideEnd) : ?>
                <div class="ccr-rental-period-field">
                    <label class="ccr-rental-period-label" for="ccr_rntp_return_date"><?php esc_html_e('Return date', 'christocentric-rentals'); ?></label>
                    <div class="ccr-rental-period-control">
                        <input
                            type="date"
                            id="ccr_rntp_return_date"
                            class="ccr-rental-period-input"
                            value="<?php echo esc_attr($defaults['end_date']); ?>"
                            min="<?php echo esc_attr($defaults['start_date']); ?>"
                            data-ccr-return-date
                            required
                        >
                        <span class="ccr-rental-period-icon" aria-hidden="true">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        </span>
                    </div>
                </div>

                <div class="ccr-rental-period-field">
                    <label class="ccr-rental-period-label" for="ccr_rntp_return_time"><?php esc_html_e('Return time', 'christocentric-rentals'); ?></label>
                    <div class="ccr-rental-period-control">
                        <input
                            type="time"
                            id="ccr_rntp_return_time"
                            class="ccr-rental-period-input"
                            value="<?php echo esc_attr($defaults['end_time']); ?>"
                            step="900"
                            data-ccr-return-time
                            required
                        >
                        <span class="ccr-rental-period-icon" aria-hidden="true">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </span>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($hoursLabel !== '') : ?>
                <p class="ccr-rental-period-hours">
                    <span class="ccr-rental-period-hours-icon" aria-hidden="true">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </span>
                    <?php echo esc_html($hoursLabel); ?>
                </p>
            <?php endif; ?>

            <p class="ccr-rental-period-status is-muted" data-ccr-period-status aria-live="polite"></p>
        </div>
        <?php
    }

    /**
     * @return array{today:string,start_date:string,start_time:string,end_date:string,end_time:string}
     */
    private static function default_period_values(): array
    {
        $today = current_time('Y-m-d');
        $startDate = $today;
        $startTime = self::normalize_time_24((string) get_option('rental_default_start_time', '09:00 AM'));
        $endDate = $today;
        $endTime = self::normalize_time_24((string) get_option('rental_default_end_time', '05:00 PM'));

        $key = (string) get_option('rental_encryption_key');
        if (! empty($_COOKIE['rental_start_date']) && function_exists('decrypt_data')) {
            $rawStart = decrypt_data(wp_unslash((string) $_COOKIE['rental_start_date']), $key);
            $parsed = self::parse_rentopian_datetime((string) $rawStart);
            if ($parsed !== null) {
                $startDate = $parsed['date'];
                $startTime = $parsed['time'];
            }
        }
        if (! empty($_COOKIE['rental_end_date']) && function_exists('decrypt_data')) {
            $rawEnd = decrypt_data(wp_unslash((string) $_COOKIE['rental_end_date']), $key);
            $parsed = self::parse_rentopian_datetime((string) $rawEnd);
            if ($parsed !== null) {
                $endDate = $parsed['date'];
                $endTime = $parsed['time'];
            }
        } else {
            // Default return = pickup + 24h when no cookie yet.
            $ts = strtotime($startDate . ' ' . $startTime . ' +1 day');
            if ($ts) {
                $endDate = wp_date('Y-m-d', $ts);
                $endTime = wp_date('H:i', $ts);
            }
        }

        return [
            'today' => $today,
            'start_date' => $startDate,
            'start_time' => $startTime,
            'end_date' => $endDate,
            'end_time' => $endTime,
        ];
    }

    private static function default_zip_value(): string
    {
        if (get_option('rental_hide_zip')) {
            return '1';
        }

        $key = (string) get_option('rental_encryption_key');
        if (! empty($_COOKIE['rental_zip']) && function_exists('decrypt_data')) {
            $raw = decrypt_data(wp_unslash((string) $_COOKIE['rental_zip']), $key);
            if (is_string($raw) && $raw !== '' && $raw !== '1' && strtolower($raw) !== 'true') {
                return $raw;
            }
        }

        $store = (string) get_option('woocommerce_store_postcode', '');
        if ($store !== '') {
            return $store;
        }

        return (string) apply_filters('ccr_rentopian_default_zip', '00233');
    }

    /**
     * @return array{date:string,time:string}|null
     */
    private static function parse_rentopian_datetime(string $value): ?array
    {
        $ts = strtotime($value);
        if (! $ts) {
            return null;
        }

        return [
            'date' => wp_date('Y-m-d', $ts),
            'time' => wp_date('H:i', $ts),
        ];
    }

    private static function normalize_time_24(string $time): string
    {
        $ts = strtotime('1970-01-01 ' . trim($time));
        if (! $ts) {
            return '09:00';
        }

        return gmdate('H:i', $ts);
    }

    public static function admin_notices(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        if (get_option('rental_direct_only_bookings')) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>Christocentric Rentals:</strong> ';
        echo esc_html__('Rentopian Sync is in quote mode (Direct bookings only is off). Paystack and Pay on pickup are hidden at checkout. Enable “Direct bookings only” in Rentopian Sync settings.', 'christocentric-rentals');
        echo '</p></div>';
    }

    /**
     * Warn when synced products are missing Rentopian inventory IDs (causes site-wide “not available”).
     */
    public static function inventory_mapping_notice(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (! $screen || ! in_array($screen->id, ['dashboard', 'edit-product', 'woocommerce_page_wc-settings', 'toplevel_page_rentopian-sync', 'rentopian-sync'], true)) {
            // Still show on plugins / any WC admin if id varies.
            if (! $screen || (strpos((string) $screen->id, 'product') === false && strpos((string) $screen->id, 'rentopian') === false && $screen->id !== 'dashboard')) {
                return;
            }
        }

        $counts = self::inventory_mapping_counts();
        if ($counts['total'] < 1) {
            return;
        }

        if ($counts['missing'] < 1) {
            return;
        }

        echo '<div class="notice notice-error"><p><strong>Christocentric Rentals:</strong> ';
        echo esc_html(sprintf(
            /* translators: 1: missing count 2: total products */
            __('%1$d of %2$d products are missing a Rentopian inventory ID. Those items will always say “not available” at add to cart. Re-run Rentopian Sync on the live domain.', 'christocentric-rentals'),
            $counts['missing'],
            $counts['total']
        ));
        echo '</p>';

        if (! empty($counts['missing_ids']) && is_array($counts['missing_ids'])) {
            echo '<p>' . esc_html__('Missing products:', 'christocentric-rentals') . '</p><ul style="margin-left:1.25em;list-style:disc;">';
            foreach ($counts['missing_ids'] as $missingId) {
                $missingId = (int) $missingId;
                $title = get_the_title($missingId);
                $edit = get_edit_post_link($missingId, 'raw');
                echo '<li>';
                if ($edit) {
                    echo '<a href="' . esc_url($edit) . '">' . esc_html($title ?: ('#' . $missingId)) . '</a>';
                } else {
                    echo esc_html($title ?: ('#' . $missingId));
                }
                echo ' <code>#' . esc_html((string) $missingId) . '</code></li>';
            }
            echo '</ul>';
        }

        echo '</div>';
    }

    /**
     * @return array{total:int,missing:int,missing_ids:int[]}
     */
    private static function inventory_mapping_counts(): array
    {
        $cached = get_transient('ccr_rentopian_inventory_map_counts');
        if (is_array($cached) && isset($cached['total'], $cached['missing'], $cached['missing_ids'])) {
            return $cached;
        }

        $q = new WP_Query([
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'no_found_rows' => true,
        ]);

        $total = 0;
        $missing = 0;
        $missingIds = [];
        foreach ($q->posts as $productId) {
            $total++;
            $inv = (int) get_post_meta((int) $productId, '_rental_inventory_id', true);
            if ($inv <= 0) {
                // Variable parents may store IDs on variations only.
                $product = wc_get_product((int) $productId);
                if ($product && $product->is_type('variable')) {
                    $childHas = false;
                    foreach ($product->get_children() as $childId) {
                        if ((int) get_post_meta((int) $childId, '_rental_inventory_id', true) > 0) {
                            $childHas = true;
                            break;
                        }
                    }
                    if (! $childHas) {
                        $missing++;
                        $missingIds[] = (int) $productId;
                    }
                } else {
                    $missing++;
                    $missingIds[] = (int) $productId;
                }
            }
        }

        $counts = [
            'total' => $total,
            'missing' => $missing,
            'missing_ids' => $missingIds,
        ];
        set_transient('ccr_rentopian_inventory_map_counts', $counts, 10 * MINUTE_IN_SECONDS);

        return $counts;
    }
}

if (! function_exists('ccr_rentopian_sync_active')) {
    function ccr_rentopian_sync_active(): bool
    {
        return CCR_Rentopian_Compat::is_active();
    }
}

if (! function_exists('ccr_rentopian_defers_rental_flow')) {
    function ccr_rentopian_defers_rental_flow(): bool
    {
        return CCR_Rentopian_Compat::defers_rental_flow();
    }
}
