<?php

defined('ABSPATH') || exit;

/**
 * Basic rental kits: a parent product that adds multiple items to the cart together.
 */
final class CCR_Product_Kits
{
    public static function init(): void
    {
        add_action('woocommerce_product_options_general_product_data', [self::class, 'render_fields'], 25);
        add_action('woocommerce_process_product_meta', [self::class, 'save_fields'], 25);
        add_filter('woocommerce_product_add_to_cart_text', [self::class, 'loop_button_text'], 10, 2);
        add_filter('woocommerce_product_single_add_to_cart_text', [self::class, 'single_button_text'], 10, 2);
        add_filter('woocommerce_add_to_cart_handler', [self::class, 'cart_handler'], 10, 2);
        add_action('woocommerce_add_to_cart_handler_ccr_kit', [self::class, 'handle_add_kit']);
        add_filter('woocommerce_get_item_data', [self::class, 'display_kit_cart_meta'], 15, 2);
    }

    public static function is_kit(int $productId): bool
    {
        return get_post_meta($productId, '_ccr_is_kit', true) === 'yes'
            && self::get_items($productId) !== [];
    }

    /**
     * @return list<array{product_id:int,quantity:int}>
     */
    public static function get_items(int $productId): array
    {
        $raw = get_post_meta($productId, '_ccr_kit_items', true);

        if (! is_array($raw)) {
            return [];
        }

        $items = [];

        foreach ($raw as $row) {
            $id = absint($row['product_id'] ?? 0);
            $qty = max(1, absint($row['quantity'] ?? 1));

            if ($id > 0 && $id !== $productId) {
                $items[] = ['product_id' => $id, 'quantity' => $qty];
            }
        }

        return $items;
    }

    public static function kit_daily_price(int $kitId): float
    {
        $override = get_post_meta($kitId, '_ccr_price_per_day', true);

        if ($override !== '' && (float) $override > 0) {
            $product = wc_get_product($kitId);

            return $product instanceof WC_Product && function_exists('ccr_product_daily_price')
                ? ccr_product_daily_price($product)
                : (float) $override;
        }

        $total = 0.0;

        foreach (self::get_items($kitId) as $item) {
            $product = wc_get_product($item['product_id']);

            if (! $product instanceof WC_Product) {
                continue;
            }

            $daily = function_exists('ccr_product_daily_price')
                ? ccr_product_daily_price($product)
                : (float) $product->get_price();

            $total += $daily * $item['quantity'];
        }

        return round($total, 2);
    }

    public static function render_fields(): void
    {
        global $post;

        $items = $post ? self::get_items((int) $post->ID) : [];
        $lines = [];

        foreach ($items as $item) {
            $lines[] = $item['product_id'] . ':' . $item['quantity'];
        }

        echo '<div class="options_group ccr-kit-fields">';

        woocommerce_wp_checkbox([
            'id' => '_ccr_is_kit',
            'label' => __('This is a rental kit (bundle)', 'christocentric-rentals'),
            'description' => __('When customers add this kit, each listed product is added to the cart with the same rental dates.', 'christocentric-rentals'),
        ]);

        woocommerce_wp_textarea_input([
            'id' => '_ccr_kit_items_raw',
            'label' => __('Kit contents', 'christocentric-rentals'),
            'description' => __('One per line: product_id:quantity (example: 42:1). Find IDs in Products list.', 'christocentric-rentals'),
            'desc_tip' => true,
            'value' => implode("\n", $lines),
        ]);

        echo '</div>';
    }

    public static function save_fields(int $postId): void
    {
        $isKit = isset($_POST['_ccr_is_kit']) ? 'yes' : 'no'; // phpcs:ignore
        update_post_meta($postId, '_ccr_is_kit', $isKit);

        $raw = isset($_POST['_ccr_kit_items_raw']) ? (string) wp_unslash($_POST['_ccr_kit_items_raw']) : ''; // phpcs:ignore
        $items = [];

        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$id, $qty] = array_pad(explode(':', $line, 2), 2, '1');
            $productId = absint($id);
            $quantity = max(1, absint($qty));

            if ($productId > 0 && $productId !== $postId && wc_get_product($productId)) {
                $items[] = ['product_id' => $productId, 'quantity' => $quantity];
            }
        }

        update_post_meta($postId, '_ccr_kit_items', $items);
    }

    public static function loop_button_text(string $text, $product): string
    {
        if ($product instanceof WC_Product && self::is_kit($product->get_id())) {
            return __('View kit', 'christocentric-rentals');
        }

        return $text;
    }

    public static function single_button_text(string $text, $product): string
    {
        if ($product instanceof WC_Product && self::is_kit($product->get_id())) {
            return __('Add kit to cart', 'christocentric-rentals');
        }

        return $text;
    }

    /**
     * How many complete kits can be rented for the date range (limited by scarcest component).
     *
     * @return array{available:int,components:list<array{product_id:int,name:string,needed:int,available:int}>,unavailable:list<string>}
     */
    public static function availability_for_dates(int $kitId, string $start, string $end, int $kitQuantity = 1): array
    {
        $kitQuantity = max(1, $kitQuantity);
        $components = [];
        $unavailable = [];
        $maxKits = PHP_INT_MAX;

        foreach (self::get_items($kitId) as $item) {
            $product = wc_get_product($item['product_id']);
            $name = $product instanceof WC_Product ? $product->get_name() : ('#' . $item['product_id']);
            $needed = max(1, (int) $item['quantity']) * $kitQuantity;
            $available = $product instanceof WC_Product
                ? CCR_Rental_Availability::available_quantity($item['product_id'], $start, $end)
                : 0;

            $components[] = [
                'product_id' => $item['product_id'],
                'name' => $name,
                'needed' => $needed,
                'available' => $available,
            ];

            if ($available < $needed) {
                $unavailable[] = $name;
            }

            $perKit = max(1, (int) $item['quantity']);
            $maxKits = min($maxKits, (int) floor($available / $perKit));
        }

        if ($maxKits === PHP_INT_MAX) {
            $maxKits = 0;
        }

        return [
            'available' => max(0, $maxKits),
            'components' => $components,
            'unavailable' => array_values(array_unique($unavailable)),
        ];
    }

    public static function cart_handler(string $handler, $product): string
    {
        if ($product instanceof WC_Product && self::is_kit($product->get_id())) {
            return 'ccr_kit';
        }

        return $handler;
    }

    public static function handle_add_kit(): void
    {
        $kitId = absint($_REQUEST['add-to-cart'] ?? 0); // phpcs:ignore

        if ($kitId <= 0 || ! self::is_kit($kitId)) {
            wc_add_notice(__('This kit is not available.', 'christocentric-rentals'), 'error');

            return;
        }

        $start = sanitize_text_field(wp_unslash($_REQUEST['ccr_rental_start'] ?? '')); // phpcs:ignore
        $end = sanitize_text_field(wp_unslash($_REQUEST['ccr_rental_end'] ?? '')); // phpcs:ignore
        $pickup = class_exists('CCR_Rental_Cart')
            ? CCR_Rental_Cart::normalize_time(sanitize_text_field(wp_unslash($_REQUEST['ccr_pickup_time'] ?? get_option('ccr_default_pickup_time', '09:00')))) // phpcs:ignore
            : sanitize_text_field(wp_unslash($_REQUEST['ccr_pickup_time'] ?? get_option('ccr_default_pickup_time', '09:00'))); // phpcs:ignore
        $return = class_exists('CCR_Rental_Cart')
            ? CCR_Rental_Cart::normalize_time(sanitize_text_field(wp_unslash($_REQUEST['ccr_return_time'] ?? get_option('ccr_default_return_time', '17:00')))) // phpcs:ignore
            : sanitize_text_field(wp_unslash($_REQUEST['ccr_return_time'] ?? get_option('ccr_default_return_time', '17:00'))); // phpcs:ignore
        $kitQty = max(1, absint($_REQUEST['quantity'] ?? 1)); // phpcs:ignore

        if ($start === '' || $end === '') {
            wc_add_notice(__('Please select rental start and end dates.', 'christocentric-rentals'), 'error');

            return;
        }

        $days = CCR_Rental_Pricing::rental_days($start, $end, $pickup, $return);

        if ($days < 1) {
            wc_add_notice(__('Return must be after pickup. Default rental is 24 hours.', 'christocentric-rentals'), 'error');

            return;
        }

        if (class_exists('CCR_Rental_Cart') && CCR_Rental_Cart::is_after_closing($return)) {
            wc_add_notice(
                sprintf(
                    /* translators: %s: closing time */
                    __('Return time must be by %s. The rental office closes then — please choose an earlier return time.', 'christocentric-rentals'),
                    CCR_Rental_Cart::closing_time_label()
                ),
                'error'
            );

            return;
        }

        $availability = self::availability_for_dates($kitId, $start, $end, $kitQty);

        if ($availability['available'] < $kitQty || $availability['unavailable'] !== []) {
            $names = $availability['unavailable'] !== []
                ? implode(', ', $availability['unavailable'])
                : __('one or more kit items', 'christocentric-rentals');

            wc_add_notice(
                sprintf(
                    /* translators: %s: list of product names */
                    __('This kit is unavailable for those dates. Booked or short: %s. Try different dates.', 'christocentric-rentals'),
                    $names
                ),
                'error'
            );

            return;
        }

        $kitKey = 'kit_' . $kitId . '_' . wp_generate_password(6, false);
        $added = 0;

        foreach (self::get_items($kitId) as $item) {
            $product = wc_get_product($item['product_id']);

            if (! $product instanceof WC_Product || ! $product->is_purchasable()) {
                wc_add_notice(
                    sprintf(
                        /* translators: %s: product name */
                        __('Kit item unavailable: %s', 'christocentric-rentals'),
                        $product ? $product->get_name() : '#' . $item['product_id']
                    ),
                    'error'
                );

                return;
            }

            $lineQty = max(1, (int) $item['quantity']) * $kitQty;
            $cartItemData = [
                'ccr_rental_start' => $start,
                'ccr_rental_end' => $end,
                'ccr_pickup_time' => $pickup,
                'ccr_return_time' => $return,
                'ccr_rental_days' => $days,
                'ccr_kit_id' => $kitId,
                'ccr_kit_key' => $kitKey,
                'unique_key' => md5($kitKey . $item['product_id'] . microtime(true)),
            ];

            $key = WC()->cart->add_to_cart($item['product_id'], $lineQty, 0, [], $cartItemData);

            if ($key) {
                $added++;
            }
        }

        if ($added > 0) {
            $kit = wc_get_product($kitId);
            wc_add_notice(
                sprintf(
                    /* translators: %s: kit name */
                    __('“%s” was added to your cart.', 'christocentric-rentals'),
                    $kit ? $kit->get_name() : __('Kit', 'christocentric-rentals')
                ),
                'success'
            );
        }
    }

    public static function render_kit_contents(): void
    {
        global $product;

        if (! $product instanceof WC_Product || ! self::is_kit($product->get_id())) {
            return;
        }

        $items = self::get_items($product->get_id());

        echo '<div class="ccr-kit-contents mt-6 rounded border border-gray-200 bg-gray-50 p-4">';
        echo '<p class="text-sm font-semibold text-gray-900">' . esc_html__('What\'s in this kit', 'christocentric-rentals') . '</p>';
        echo '<ul class="mt-3 space-y-2 text-sm text-gray-700">';

        foreach ($items as $item) {
            $child = wc_get_product($item['product_id']);

            if (! $child instanceof WC_Product) {
                continue;
            }

            $daily = function_exists('ccr_product_daily_price')
                ? ccr_product_daily_price($child)
                : (float) $child->get_price();

            echo '<li class="flex items-center justify-between gap-3">';
            echo '<a class="hover:text-primary" href="' . esc_url(get_permalink($child->get_id())) . '">' . esc_html($child->get_name()) . '</a>';
            echo '<span class="shrink-0 text-gray-500">×' . (int) $item['quantity'];
            if (function_exists('ccr_format_price')) {
                echo ' · ' . esc_html(ccr_format_price($daily)) . '/day';
            }
            echo '</span></li>';
        }

        echo '</ul></div>';
    }

    public static function display_kit_cart_meta(array $itemData, array $cartItem): array
    {
        if (empty($cartItem['ccr_kit_id'])) {
            return $itemData;
        }

        $kit = wc_get_product((int) $cartItem['ccr_kit_id']);

        if ($kit) {
            $itemData[] = [
                'name' => __('Kit', 'christocentric-rentals'),
                'value' => $kit->get_name(),
            ];
        }

        return $itemData;
    }
}
