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
        add_filter('woocommerce_add_to_cart_validation', [self::class, 'validate_add_to_cart'], 10, 3);
        add_filter('woocommerce_add_cart_item_data', [self::class, 'add_cart_item_data'], 10, 3);
        add_filter('woocommerce_get_item_data', [self::class, 'display_cart_item_data'], 10, 2);
        add_action('woocommerce_before_calculate_totals', [self::class, 'adjust_cart_prices'], 20);
        add_action('woocommerce_checkout_create_order_line_item', [self::class, 'save_order_item_meta'], 10, 4);
        add_action('woocommerce_check_cart_items', [self::class, 'validate_cart_availability']);
        add_action('wp_ajax_ccr_rental_quote', [self::class, 'ajax_quote']);
        add_action('wp_ajax_nopriv_ccr_rental_quote', [self::class, 'ajax_quote']);
        add_action('wp_ajax_ccr_quick_add', [self::class, 'ajax_quick_add']);
        add_action('wp_ajax_nopriv_ccr_quick_add', [self::class, 'ajax_quick_add']);
    }

    /**
     * Default 24-hour rental window used by product-card quick add.
     *
     * @return array{start:string,end:string,pickup:string,return:string,days:int}
     */
    public static function default_rental_window(): array
    {
        $pickup = self::normalize_time((string) get_option('ccr_default_pickup_time', '09:00'));
        $latest = self::latest_return_time();
        $start = current_time('Y-m-d');

        if (current_time('H:i') > $latest) {
            $start = wp_date('Y-m-d', strtotime($start . ' +1 day'));
        }

        $auto = CCR_Rental_Pricing::add_hours_clamped($start, $pickup, 24, $latest) ?? [$start, $pickup];
        $end = $auto[0];
        $return = $auto[1];
        $days = CCR_Rental_Pricing::rental_days($start, $end, $pickup, $return);

        return [
            'start' => $start,
            'end' => $end,
            'pickup' => $pickup,
            'return' => $return,
            'days' => max(1, $days),
        ];
    }

    public static function ajax_quick_add(): void
    {
        check_ajax_referer('ccr_quick_add', 'nonce');

        if (! function_exists('WC') || ! WC()->cart) {
            wp_send_json_error(['message' => __('Cart is not available.', 'christocentric-rentals')]);
        }

        $productId = absint($_POST['product_id'] ?? 0); // phpcs:ignore
        $product = wc_get_product($productId);

        if (! $product instanceof WC_Product || $product->get_status() !== 'publish') {
            wp_send_json_error(['message' => __('This item is unavailable.', 'christocentric-rentals')]);
        }

        $daily = function_exists('ccr_product_daily_price')
            ? ccr_product_daily_price($product)
            : (float) $product->get_meta('_ccr_price_per_day');
        if ($daily <= 0) {
            $daily = (float) $product->get_regular_price('edit');
        }
        if ($daily <= 0) {
            wp_send_json_error([
                'message' => __('This item has no rental price yet. Open the product to check, or contact us.', 'christocentric-rentals'),
            ]);
        }

        if (! $product->is_in_stock()) {
            wp_send_json_error(['message' => __('This item is unavailable.', 'christocentric-rentals')]);
        }

        $window = self::default_rental_window();
        $start = $window['start'];
        $end = $window['end'];
        $pickup = $window['pickup'];
        $return = $window['return'];
        $days = $window['days'];

        // So add_cart_item_data / validation see the same rental window.
        $_REQUEST['ccr_rental_start'] = $start;
        $_REQUEST['ccr_rental_end'] = $end;
        $_REQUEST['ccr_pickup_time'] = $pickup;
        $_REQUEST['ccr_return_time'] = $return;
        $_POST['ccr_rental_start'] = $start;
        $_POST['ccr_rental_end'] = $end;
        $_POST['ccr_pickup_time'] = $pickup;
        $_POST['ccr_return_time'] = $return;

        $isKit = class_exists('CCR_Product_Kits') && CCR_Product_Kits::is_kit($productId);

        if ($isKit) {
            $availability = CCR_Product_Kits::availability_for_dates($productId, $start, $end, 1);
            if ($availability['available'] < 1 || $availability['unavailable'] !== []) {
                wp_send_json_error([
                    'message' => __('This kit is not available for the default 24-hour window. Open the product to pick other dates.', 'christocentric-rentals'),
                ]);
            }

            $kitKey = 'kit_' . $productId . '_' . wp_generate_password(6, false);
            $added = 0;

            foreach (CCR_Product_Kits::get_items($productId) as $item) {
                $child = wc_get_product($item['product_id']);
                if (! $child instanceof WC_Product || ! $child->is_purchasable()) {
                    continue;
                }
                $lineQty = max(1, (int) $item['quantity']);
                $key = WC()->cart->add_to_cart($item['product_id'], $lineQty, 0, [], [
                    'ccr_rental_start' => $start,
                    'ccr_rental_end' => $end,
                    'ccr_pickup_time' => $pickup,
                    'ccr_return_time' => $return,
                    'ccr_rental_days' => $days,
                    'ccr_kit_id' => $productId,
                    'ccr_kit_key' => $kitKey,
                    'unique_key' => md5($kitKey . $item['product_id'] . microtime(true)),
                ]);
                if ($key) {
                    $added++;
                }
            }

            if ($added < 1) {
                wp_send_json_error(['message' => __('Could not add this kit to the cart.', 'christocentric-rentals')]);
            }
        } else {
            $available = CCR_Rental_Availability::available_quantity($productId, $start, $end);
            if ($available < 1) {
                wp_send_json_error([
                    'message' => __('Not available for the default 24-hour window. Open the product to pick other dates.', 'christocentric-rentals'),
                ]);
            }

            $key = WC()->cart->add_to_cart($productId, 1, 0, [], [
                'ccr_rental_start' => $start,
                'ccr_rental_end' => $end,
                'ccr_pickup_time' => $pickup,
                'ccr_return_time' => $return,
                'ccr_rental_days' => $days,
                'unique_key' => md5($productId . $start . $end . $pickup . $return . microtime(true)),
            ]);

            if (! $key) {
                wp_send_json_error(['message' => __('Could not add this item to the cart.', 'christocentric-rentals')]);
            }
        }

        wp_send_json_success([
            'message' => __('Added with default 24-hour rental. Change dates on the product page if needed.', 'christocentric-rentals'),
            'cart_count' => (int) WC()->cart->get_cart_contents_count(),
            'cart_url' => wc_get_cart_url(),
        ]);
    }

    public static function latest_return_time(): string
    {
        return self::normalize_time((string) get_option('ccr_latest_return_time', '20:50'));
    }

    public static function normalize_time(string $time): string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})/', trim($time), $m)) {
            $h = min(23, max(0, (int) $m[1]));
            $i = min(59, max(0, (int) $m[2]));

            return sprintf('%02d:%02d', $h, $i);
        }

        return '20:50';
    }

    public static function is_after_closing(string $time): bool
    {
        return self::normalize_time($time) > self::latest_return_time();
    }

    public static function closing_time_label(): string
    {
        $time = self::latest_return_time();
        $ts = strtotime('1970-01-01 ' . $time);

        return $ts ? date_i18n(get_option('time_format', 'g:i a'), $ts) : $time;
    }

    /**
     * @param mixed $passed
     * @param mixed $productId
     * @param mixed $quantity
     */
    public static function validate_add_to_cart($passed, $productId, $quantity): bool
    {
        if (! $passed) {
            return false;
        }

        $return = sanitize_text_field(wp_unslash((string) ($_POST['ccr_return_time'] ?? $_REQUEST['ccr_return_time'] ?? '')));
        if ($return === '') {
            $return = (string) get_option('ccr_default_return_time', '17:00');
        }

        if (self::is_after_closing($return)) {
            wc_add_notice(
                sprintf(
                    /* translators: %s: closing time */
                    __('Return time must be by %s. The rental office closes then — please choose an earlier return time.', 'christocentric-rentals'),
                    self::closing_time_label()
                ),
                'error'
            );

            return false;
        }

        return (bool) $passed;
    }

    public static function render_product_fields(): void
    {
        global $product;

        if (! $product instanceof WC_Product) {
            return;
        }

        $pickupDefault = (string) get_option('ccr_default_pickup_time', '09:00');
        $latestReturn = self::latest_return_time();
        $today = current_time('Y-m-d');
        $startDefault = $today;
        $autoReturn = CCR_Rental_Pricing::add_hours_clamped($startDefault, $pickupDefault, 24, $latestReturn);
        $endDefault = $autoReturn[0] ?? $today;
        $returnDefault = $autoReturn[1] ?? $pickupDefault;

        wp_enqueue_script('ccr-rental', CCR_PLUGIN_URL . 'assets/rental-fields.js', ['jquery'], CCR_VERSION, true);
        wp_localize_script('ccr-rental', 'ccrRental', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'productId' => $product->get_id(),
            'isKit' => class_exists('CCR_Product_Kits') && CCR_Product_Kits::is_kit($product->get_id()),
            'nonce' => wp_create_nonce('ccr_rental_quote'),
            'latestReturnTime' => $latestReturn,
            'closingLabel' => self::closing_time_label(),
            'defaultPeriodHours' => 24,
            'i18n' => [
                'returnTooLate' => sprintf(
                    /* translators: %s: closing time */
                    __('Return time must be before %s (office closing time).', 'christocentric-rentals'),
                    self::closing_time_label()
                ),
                'quoteOne' => __('1 × 24 hours', 'christocentric-rentals'),
                /* translators: %d: number of 24-hour periods */
                'quoteMany' => __('%d × 24 hours', 'christocentric-rentals'),
                /* translators: 1: available 2: fleet */
                'availableOf' => __('%1$d of %2$d available for these dates', 'christocentric-rentals'),
                'availableOne' => __('%d available for these dates', 'christocentric-rentals'),
            ],
        ]);

        ?>
        <div class="space-y-5 ccr-rental-fields" data-return-auto="1">
            <div class="rental-datetime-group">
                <p class="rental-datetime-label"><?php esc_html_e('Pickup date & time', 'christocentric-rentals'); ?></p>
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
                <p class="rental-datetime-label"><?php esc_html_e('Return date & time', 'christocentric-rentals'); ?></p>
                <div class="rental-datetime-row">
                    <div class="rental-datetime-field">
                        <span class="rental-datetime-icon" aria-hidden="true">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        </span>
                        <input type="date" id="ccr_rental_end" name="ccr_rental_end" value="<?php echo esc_attr($endDefault); ?>" min="<?php echo esc_attr($startDefault); ?>" required class="rental-datetime-input" data-rental-end>
                    </div>
                    <div class="rental-datetime-field">
                        <span class="rental-datetime-icon" aria-hidden="true">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </span>
                        <input type="time" id="ccr_return_time" name="ccr_return_time" value="<?php echo esc_attr($returnDefault); ?>" max="<?php echo esc_attr($latestReturn); ?>" required class="rental-datetime-input" data-rental-return-time>
                    </div>
                </div>
            </div>

            <p class="ccr-quote text-sm font-medium text-red-600" aria-live="polite"></p>
        </div>
        <?php
    }

    public static function add_cart_item_data(array $cartItemData, int $productId, $variationId): array
    {
        $start = sanitize_text_field(wp_unslash($_POST['ccr_rental_start'] ?? '')); // phpcs:ignore
        $end = sanitize_text_field(wp_unslash($_POST['ccr_rental_end'] ?? '')); // phpcs:ignore
        $pickup = self::normalize_time(sanitize_text_field(wp_unslash($_POST['ccr_pickup_time'] ?? get_option('ccr_default_pickup_time', '09:00')))); // phpcs:ignore
        $return = self::normalize_time(sanitize_text_field(wp_unslash($_POST['ccr_return_time'] ?? get_option('ccr_default_return_time', '17:00')))); // phpcs:ignore

        if ($start === '' || $end === '') {
            wc_add_notice(__('Please select rental start and end dates.', 'christocentric-rentals'), 'error');

            return $cartItemData;
        }

        $days = CCR_Rental_Pricing::rental_days($start, $end, $pickup, $return);

        if ($days < 1) {
            wc_add_notice(__('Return must be after pickup. Default rental is 24 hours.', 'christocentric-rentals'), 'error');

            return $cartItemData;
        }

        $cartItemData['ccr_rental_start'] = $start;
        $cartItemData['ccr_rental_end'] = $end;
        $cartItemData['ccr_pickup_time'] = $pickup;
        $cartItemData['ccr_return_time'] = $return;
        $cartItemData['ccr_rental_days'] = $days;
        $cartItemData['unique_key'] = md5($productId . $start . $end . $pickup . $return . microtime(true));

        return $cartItemData;
    }

    public static function display_cart_item_data(array $itemData, array $cartItem): array
    {
        if (! empty($cartItem['ccr_rental_start'])) {
            $pickup = $cartItem['ccr_pickup_time'] ?? '';
            $return = $cartItem['ccr_return_time'] ?? '';
            $itemData[] = [
                'name' => __('Rental period', 'christocentric-rentals'),
                'value' => esc_html(
                    CCR_Rental_Pricing::format_schedule((string) $cartItem['ccr_rental_start'], $pickup ?: null)
                    . ' → '
                    . CCR_Rental_Pricing::format_schedule((string) $cartItem['ccr_rental_end'], $return ?: null)
                ),
            ];
        }

        if (! empty($cartItem['ccr_rental_days'])) {
            $itemData[] = [
                'name' => __('24hr periods', 'christocentric-rentals'),
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

            if (! $product instanceof WC_Product) {
                continue;
            }

            $daily = function_exists('ccr_product_daily_price')
                ? ccr_product_daily_price($product)
                : (float) get_post_meta($product->get_id(), '_ccr_price_per_day', true);

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

        $product = $item->get_product();
        if ($product instanceof WC_Product) {
            $daily = function_exists('ccr_product_regular_daily_price')
                ? ccr_product_regular_daily_price($product)
                : (float) get_post_meta($product->get_id(), '_ccr_price_per_day', true);
            if ($daily > 0) {
                $item->add_meta_data('_ccr_price_per_day', $daily, true);
            }
        }
    }

    public static function validate_cart_availability(): void
    {
        foreach (WC()->cart->get_cart() as $cartItem) {
            $return = (string) ($cartItem['ccr_return_time'] ?? '');
            if ($return !== '' && self::is_after_closing($return)) {
                $name = isset($cartItem['data']) && $cartItem['data'] instanceof WC_Product
                    ? $cartItem['data']->get_name()
                    : __('Item', 'christocentric-rentals');
                wc_add_notice(
                    sprintf(
                        /* translators: 1: product name, 2: closing time */
                        __('%1$s — return time must be by %2$s (office closing). Update the rental times or remove this item.', 'christocentric-rentals'),
                        $name,
                        self::closing_time_label()
                    ),
                    'error'
                );
            }
        }

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
        $pickup = sanitize_text_field(wp_unslash($_POST['pickup'] ?? get_option('ccr_default_pickup_time', '09:00'))); // phpcs:ignore
        $return = sanitize_text_field(wp_unslash($_POST['return'] ?? get_option('ccr_default_return_time', '17:00'))); // phpcs:ignore
        $quantity = max(1, absint($_POST['quantity'] ?? 1)); // phpcs:ignore

        $days = CCR_Rental_Pricing::rental_days($start, $end, $pickup, $return);
        $product = wc_get_product($productId);
        $daily = 0.0;
        $isKit = class_exists('CCR_Product_Kits') && CCR_Product_Kits::is_kit($productId);
        $available = 0;
        $unavailable = [];

        if ($product instanceof WC_Product) {
            if ($isKit) {
                $daily = CCR_Product_Kits::kit_daily_price($productId);
                $kitAvailability = CCR_Product_Kits::availability_for_dates($productId, $start, $end, $quantity);
                $available = (int) $kitAvailability['available'];
                $unavailable = $kitAvailability['unavailable'];
            } elseif (function_exists('ccr_product_daily_price')) {
                $daily = ccr_product_daily_price($product);
                $available = CCR_Rental_Availability::available_quantity($productId, $start, $end);
            } else {
                $daily = (float) get_post_meta($productId, '_ccr_price_per_day', true);
                if ($daily <= 0) {
                    $daily = (float) $product->get_regular_price();
                }
                $available = CCR_Rental_Availability::available_quantity($productId, $start, $end);
            }
        }

        wp_send_json_success([
            'days' => $days,
            'total' => CCR_Rental_Pricing::line_total($daily, max(1, $days), $quantity),
            'available' => $available,
            'fleet' => $isKit ? $available : CCR_Rental_Availability::fleet_quantity($productId),
            'max_quantity' => $available,
            'is_kit' => $isKit,
            'unavailable_items' => $unavailable,
            'formatted_total' => CCR_Rental_Pricing::format(CCR_Rental_Pricing::line_total($daily, max(1, $days), $quantity)),
        ]);
    }
}
