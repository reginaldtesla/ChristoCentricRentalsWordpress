<?php

defined('ABSPATH') || exit;

/**
 * Mirrors Laravel App\Services\RentopianSyncService.
 */
final class CCR_Rentopian_Sync
{
    public static function init(): void
    {
        add_action('init', [self::class, 'maybe_fix_base_url'], 4);
        add_action('woocommerce_payment_complete', [self::class, 'maybe_push_order'], 20, 1);
        add_action('woocommerce_order_status_processing', [self::class, 'maybe_push_order'], 20, 1);
        add_action('woocommerce_order_status_completed', [self::class, 'maybe_push_order'], 20, 1);
        add_action('woocommerce_order_status_on-hold', [self::class, 'maybe_push_order'], 20, 1);
        add_action('ccr_order_marked_paid', [self::class, 'maybe_push_order'], 10, 1);
    }

    public static function is_configured(): bool
    {
        $key = get_option('ccr_rentopian_api_key', '');

        return is_string($key) && trim($key) !== '';
    }

    public static function default_base_url(): string
    {
        return 'https://account.rentopian.com/api/v1';
    }

    public static function website_domain(): string
    {
        $saved = trim((string) get_option('ccr_rentopian_website', ''));
        if ($saved !== '') {
            return untrailingslashit($saved);
        }

        return 'https://christocentricrentals.com';
    }

    public static function maybe_fix_base_url(): void
    {
        $base = rtrim((string) get_option('ccr_rentopian_base_url', ''), '/');
        if ($base === '' || str_contains($base, 'api.rentopian.com')) {
            update_option('ccr_rentopian_base_url', self::default_base_url());
        }
        if (trim((string) get_option('ccr_rentopian_website', '')) === '') {
            update_option('ccr_rentopian_website', self::website_domain());
        }
    }

    public static function maybe_push_order($orderId): void
    {
        $order = wc_get_order($orderId);

        if (! $order) {
            return;
        }

        if ((string) $order->get_meta('_ccr_is_studio_booking') === '1') {
            return;
        }

        if (! $order->is_paid() && ! $order->has_status(['processing', 'completed', 'on-hold'])) {
            return;
        }

        if ($order->get_meta('_ccr_rentopian_synced') === 'yes') {
            return;
        }

        if (defined('RENTOPIAN_SYNC_VERSION')) {
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

            $wooProduct = $item->get_product();
            $rentopianId = '';
            if ($wooProduct instanceof WC_Product) {
                $rentopianId = (string) $wooProduct->get_meta('_ccr_rentopian_id');
            }

            $items[] = [
                'id' => $rentopianId !== '' ? (int) $rentopianId : $item->get_product_id(),
                'product_id' => $rentopianId !== '' ? (int) $rentopianId : $item->get_product_id(),
                'woo_product_id' => $item->get_product_id(),
                'rentopian_id' => $rentopianId,
                'name' => $item->get_name(),
                'days' => $days > 0 ? $days : 1,
                'quantity' => $item->get_quantity(),
                'qty' => $item->get_quantity(),
                'line_total' => (float) $item->get_total(),
                'start_date' => $start,
                'end_date' => $end,
            ];
        }

        $payload = [
            'website_url' => self::website_domain(),
            'website' => self::website_domain(),
            'order_number' => $order->get_order_number(),
            'reference' => $order->get_order_number(),
            'customer' => [
                'name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'first_name' => $order->get_billing_first_name(),
                'last_name' => $order->get_billing_last_name(),
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone(),
            ],
            'rental_start' => $rentalStart,
            'rental_end' => $rentalEnd,
            'start_date' => $rentalStart,
            'end_date' => $rentalEnd,
            'event_start' => $rentalStart,
            'event_end' => $rentalEnd,
            'total' => (float) $order->get_total(),
            'status' => $order->get_status(),
            'paid' => $order->is_paid(),
            'items' => $items,
            'products' => $items,
        ];

        $paths = array_values(array_unique(array_filter([
            (string) get_option('ccr_rentopian_order_path', ''),
            '/quotes',
            '/transactions',
            '/website-orders',
            '/website/orders',
            '/bookings',
            '/leads',
            '/events',
            '/orders',
        ])));

        $ok = false;
        $lastError = '';
        foreach ($paths as $path) {
            $result = self::request('POST', $path, $payload);
            if ($result['ok']) {
                update_option('ccr_rentopian_order_path', $path);
                $order->update_meta_data('_ccr_rentopian_synced', 'yes');
                $order->update_meta_data('_ccr_rentopian_sync_path', $path);
                $order->delete_meta_data('_ccr_rentopian_sync_error');
                $order->save();
                $ok = true;
                break;
            }
            $lastError = $path . ' → ' . ($result['error'] !== '' ? $result['error'] : (string) $result['code']);
        }

        if (! $ok) {
            $order->update_meta_data('_ccr_rentopian_sync_error', $lastError);
            $order->save();
            error_log('[Christocentric Rentals] Rentopian order sync failed: ' . $lastError);
        }
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array{ok:bool,code:int,body:mixed,error:string}
     */
    public static function request(string $method, string $path, ?array $body = null): array
    {
        $out = ['ok' => false, 'code' => 0, 'body' => null, 'error' => ''];
        if (! self::is_configured()) {
            $out['error'] = 'API key not configured';

            return $out;
        }

        $baseUrl = rtrim((string) get_option('ccr_rentopian_base_url', self::default_base_url()), '/');
        $apiKey = (string) get_option('ccr_rentopian_api_key', '');
        $args = [
            'method' => strtoupper($method),
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Domain' => self::website_domain(),
            ],
            'timeout' => 90,
        ];
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($baseUrl . $path, $args);
        if (is_wp_error($response)) {
            $out['error'] = $response->get_error_message();
            error_log('[Christocentric Rentals] Rentopian ' . $method . ' ' . $path . ' error: ' . $out['error']);

            return $out;
        }

        $out['code'] = (int) wp_remote_retrieve_response_code($response);
        $raw = (string) wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);
        $out['body'] = is_array($decoded) ? $decoded : $raw;
        $out['ok'] = $out['code'] >= 200 && $out['code'] < 300;
        if (! $out['ok']) {
            $out['error'] = is_string($raw) && $raw !== '' ? $raw : ('HTTP ' . $out['code']);
            error_log('[Christocentric Rentals] Rentopian ' . $method . ' ' . $path . ' failed (' . $out['code'] . '): ' . $out['error']);
        }

        return $out;
    }
}
