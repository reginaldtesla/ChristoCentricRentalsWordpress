<?php

defined('ABSPATH') || exit;

/**
 * Mirrors Laravel App\Services\RentopianSyncService.
 */
final class CCR_Rentopian_Sync
{
    public static function init(): void
    {
        add_action('woocommerce_payment_complete', [self::class, 'maybe_push_order'], 20, 1);
        add_action('woocommerce_order_status_processing', [self::class, 'maybe_push_order'], 20, 1);
        add_action('woocommerce_order_status_completed', [self::class, 'maybe_push_order'], 20, 1);
        add_action('ccr_order_marked_paid', [self::class, 'maybe_push_order'], 10, 1);
    }

    public static function is_configured(): bool
    {
        $key = get_option('ccr_rentopian_api_key', '');

        return is_string($key) && trim($key) !== '';
    }

    public static function maybe_push_order($orderId): void
    {
        $order = wc_get_order($orderId);

        if (! $order || ! $order->is_paid()) {
            return;
        }

        if ($order->get_meta('_ccr_rentopian_synced') === 'yes') {
            return;
        }

        self::push_order($order);
    }

    public static function push_order(WC_Order $order): void
    {
        if (! self::is_configured()) {
            error_log('[Christocentric Rentals] Rentopian sync skipped — API key not configured. Order: ' . $order->get_order_number());

            return;
        }

        $items = [];
        $rentalStart = null;
        $rentalEnd = null;

        foreach ($order->get_items() as $item) {
            if (! $item instanceof WC_Order_Item_Product) {
                continue;
            }

            $start = (string) $item->get_meta('_ccr_rental_start');
            $end = (string) $item->get_meta('_ccr_rental_end');
            $days = (int) $item->get_meta('_ccr_rental_days');

            if ($start !== '') {
                $rentalStart = $rentalStart === null ? $start : min($rentalStart, $start);
            }
            if ($end !== '') {
                $rentalEnd = $rentalEnd === null ? $end : max($rentalEnd, $end);
            }

            $items[] = [
                'product_id' => $item->get_product_id(),
                'name' => $item->get_name(),
                'days' => $days > 0 ? $days : 1,
                'quantity' => $item->get_quantity(),
                'line_total' => (float) $item->get_total(),
            ];
        }

        $payload = [
            'website_url' => home_url('/'),
            'order_number' => $order->get_order_number(),
            'customer' => [
                'name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone(),
            ],
            'rental_start' => $rentalStart,
            'rental_end' => $rentalEnd,
            'total' => (float) $order->get_total(),
            'items' => $items,
        ];

        $baseUrl = rtrim((string) get_option('ccr_rentopian_base_url', 'https://api.rentopian.com'), '/');
        $apiKey = (string) get_option('ccr_rentopian_api_key', '');

        $response = wp_remote_post($baseUrl . '/orders', [
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            error_log('[Christocentric Rentals] Rentopian sync error: ' . $response->get_error_message());

            return;
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code < 200 || $code >= 300) {
            error_log('[Christocentric Rentals] Rentopian sync failed (' . $code . '): ' . wp_remote_retrieve_body($response));

            return;
        }

        $order->update_meta_data('_ccr_rentopian_synced', 'yes');
        $order->save();
    }
}
