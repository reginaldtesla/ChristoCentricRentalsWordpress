<?php

defined('ABSPATH') || exit;

/**
 * Rental date fields on cart/checkout and order line items.
 */
final class CCR_Rental_Cart
{
    public static function init(): void
    {
        add_action('woocommerce_before_add_to_cart_button', [self::class, 'render_product_fields']);
        add_filter('woocommerce_add_cart_item_data', [self::class, 'add_cart_item_data'], 10, 3);
        add_filter('woocommerce_get_item_data', [self::class, 'display_cart_item_data'], 10, 2);
        add_action('woocommerce_before_calculate_totals', [self::class, 'adjust_cart_prices'], 20);
        add_action('woocommerce_checkout_create_order_line_item', [self::class, 'save_order_item_meta'], 10, 4);
        add_action('woocommerce_check_cart_items', [self::class, 'validate_cart_availability']);
        add_action('wp_ajax_ccr_rental_quote', [self::class, 'ajax_quote']);
        add_action('wp_ajax_nopriv_ccr_rental_quote', [self::class, 'ajax_quote']);
    }

    public static function render_product_fields(): void
    {
        global $product;

        if (! $product instanceof WC_Product) {
            return;
        }

        $pickupDefault = get_option('ccr_default_pickup_time', '09:00');
        $returnDefault = get_option('ccr_default_return_time', '17:00');
        $today = gmdate('Y-m-d');
        $startDefault = gmdate('Y-m-d', strtotime('+1 day'));
        $endDefault = gmdate('Y-m-d', strtotime('+2 days'));

        wp_enqueue_script('ccr-rental', CCR_PLUGIN_URL . 'assets/rental-fields.js', ['jquery'], CCR_VERSION, true);
        wp_localize_script('ccr-rental', 'ccrRental', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'productId' => $product->get_id(),
            'nonce' => wp_create_nonce('ccr_rental_quote'),
        ]);

        ?>
        <div class="space-y-5 ccr-rental-fields">
            <div class="rental-datetime-group">
                <p class="rental-datetime-label"><?php esc_html_e('Pickup date', 'christocentric-rentals'); ?></p>
                <div class="rental-datetime-row">
                    <div class="rental-datetime-field">
                        <span class="rental-datetime-icon" aria-hidden="true">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        </span>
                        <input type="date" id="ccr_rental_start" name="ccr_rental_start" value="<?php echo esc_attr($startDefault); ?>" min="<?php echo esc_attr($today); ?>" required class="rental-datetime-input" data-rental-start>
                    </div>
                    <div class="rental-datetime-field">
                        <span class="rental-datetime-icon" aria-hidden="true">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </span>
                        <input type="time" id="ccr_pickup_time" name="ccr_pickup_time" value="<?php echo esc_attr($pickupDefault); ?>" required class="rental-datetime-input" data-rental-pickup-time>
                    </div>
                </div>
            </div>

            <div class="rental-datetime-group">
                <p class="rental-datetime-label"><?php esc_html_e('Return date', 'christocentric-rentals'); ?></p>
                <div class="rental-datetime-row">
                    <div class="rental-datetime-field">
                        <span class="rental-datetime-icon" aria-hidden="true">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        </span>
                        <input type="date" id="ccr_rental_end" name="ccr_rental_end" value="<?php echo esc_attr($endDefault); ?>" min="<?php echo esc_attr($today); ?>" required class="rental-datetime-input" data-rental-end>
                    </div>
                    <div class="rental-datetime-field">
                        <span class="rental-datetime-icon" aria-hidden="true">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </span>
                        <input type="time" id="ccr_return_time" name="ccr_return_time" value="<?php echo esc_attr($returnDefault); ?>" required class="rental-datetime-input" data-rental-return-time>
                    </div>
                </div>
            </div>

            <p class="ccr-quote text-sm text-gray-600" aria-live="polite"></p>
        </div>
        <?php
    }

    public static function add_cart_item_data(array $cartItemData, int $productId, $variationId): array
    {
        $start = sanitize_text_field(wp_unslash($_POST['ccr_rental_start'] ?? '')); // phpcs:ignore
        $end = sanitize_text_field(wp_unslash($_POST['ccr_rental_end'] ?? '')); // phpcs:ignore

        if ($start === '' || $end === '') {
            wc_add_notice(__('Please select rental start and end dates.', 'christocentric-rentals'), 'error');

            return $cartItemData;
        }

        $days = CCR_Rental_Pricing::rental_days($start, $end);

        if ($days < 1) {
            wc_add_notice(__('End date must be on or after start date.', 'christocentric-rentals'), 'error');

            return $cartItemData;
        }

        $cartItemData['ccr_rental_start'] = $start;
        $cartItemData['ccr_rental_end'] = $end;
        $cartItemData['ccr_pickup_time'] = sanitize_text_field(wp_unslash($_POST['ccr_pickup_time'] ?? get_option('ccr_default_pickup_time', '09:00'))); // phpcs:ignore
        $cartItemData['ccr_return_time'] = sanitize_text_field(wp_unslash($_POST['ccr_return_time'] ?? get_option('ccr_default_return_time', '17:00'))); // phpcs:ignore
        $cartItemData['ccr_rental_days'] = $days;
        $cartItemData['unique_key'] = md5($productId . $start . $end . microtime(true));

        return $cartItemData;
    }

    public static function display_cart_item_data(array $itemData, array $cartItem): array
    {
        if (! empty($cartItem['ccr_rental_start'])) {
            $itemData[] = [
                'name' => __('Rental period', 'christocentric-rentals'),
                'value' => esc_html($cartItem['ccr_rental_start'] . ' → ' . $cartItem['ccr_rental_end']),
            ];
        }

        if (! empty($cartItem['ccr_rental_days'])) {
            $itemData[] = [
                'name' => __('Days', 'christocentric-rentals'),
                'value' => (int) $cartItem['ccr_rental_days'],
            ];
        }

        return $itemData;
    }

    public static function adjust_cart_prices(WC_Cart $cart): void
    {
        foreach ($cart->get_cart() as $cartItem) {
            $product = $cartItem['data'];
            $days = (int) ($cartItem['ccr_rental_days'] ?? 1);
            $daily = (float) get_post_meta($product->get_id(), '_ccr_price_per_day', true);

            if ($daily <= 0) {
                $daily = (float) $product->get_regular_price();
            }

            $product->set_price(CCR_Rental_Pricing::line_total($daily, max(1, $days), 1));
        }
    }

    public static function save_order_item_meta(WC_Order_Item_Product $item, string $cartItemKey, array $values, WC_Order $order): void
    {
        foreach ([
            '_ccr_rental_start' => 'ccr_rental_start',
            '_ccr_rental_end' => 'ccr_rental_end',
            '_ccr_pickup_time' => 'ccr_pickup_time',
            '_ccr_return_time' => 'ccr_return_time',
            '_ccr_rental_days' => 'ccr_rental_days',
        ] as $metaKey => $cartKey) {
            if (! empty($values[$cartKey])) {
                $item->add_meta_data($metaKey, $values[$cartKey], true);
            }
        }
    }

    public static function validate_cart_availability(): void
    {
        $items = [];

        foreach (WC()->cart->get_cart() as $cartItem) {
            $items[] = [
                'product_id' => $cartItem['product_id'],
                'rental_start' => $cartItem['ccr_rental_start'] ?? '',
                'rental_end' => $cartItem['ccr_rental_end'] ?? '',
                'quantity' => $cartItem['quantity'],
            ];
        }

        foreach (CCR_Rental_Availability::validate_items($items) as $error) {
            wc_add_notice(
                sprintf(
                    /* translators: 1: product name, 2: available qty */
                    __('%1$s — only %2$d available for those dates.', 'christocentric-rentals'),
                    $error['name'],
                    $error['available']
                ),
                'error'
            );
        }
    }

    public static function ajax_quote(): void
    {
        check_ajax_referer('ccr_rental_quote', 'nonce');

        $productId = absint($_POST['product_id'] ?? 0); // phpcs:ignore
        $start = sanitize_text_field(wp_unslash($_POST['start'] ?? '')); // phpcs:ignore
        $end = sanitize_text_field(wp_unslash($_POST['end'] ?? '')); // phpcs:ignore
        $quantity = max(1, absint($_POST['quantity'] ?? 1)); // phpcs:ignore

        $days = CCR_Rental_Pricing::rental_days($start, $end);
        $product = wc_get_product($productId);
        $daily = $product ? (float) get_post_meta($productId, '_ccr_price_per_day', true) : 0;

        if ($daily <= 0 && $product) {
            $daily = (float) $product->get_regular_price();
        }

        wp_send_json_success([
            'days' => $days,
            'total' => CCR_Rental_Pricing::line_total($daily, max(1, $days), $quantity),
            'available' => CCR_Rental_Availability::available_quantity($productId, $start, $end),
            'max_quantity' => CCR_Rental_Availability::available_quantity($productId, $start, $end),
            'formatted_total' => CCR_Rental_Pricing::format(CCR_Rental_Pricing::line_total($daily, max(1, $days), $quantity)),
        ]);
    }
}
