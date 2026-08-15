<?php

defined('ABSPATH') || exit;

/**
 * Auto-cancel unpaid pickup-cash / abandoned checkout orders past hold windows.
 */
final class CCR_Hold_Expiry
{
    public const HOOK = 'ccr_expire_unpaid_holds';

    public static function init(): void
    {
        add_action(self::HOOK, [self::class, 'run']);
        add_action('init', [self::class, 'maybe_schedule']);
    }

    public static function schedule(): void
    {
        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 60, 'hourly', self::HOOK);
        }
    }

    public static function unschedule(): void
    {
        $timestamp = wp_next_scheduled(self::HOOK);
        while ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK);
            $timestamp = wp_next_scheduled(self::HOOK);
        }
    }

    public static function maybe_schedule(): void
    {
        self::schedule();
    }

    public static function run(): void
    {
        if (! function_exists('wc_get_orders')) {
            return;
        }

        $pickupHours = max(1, (int) get_option('ccr_pickup_cash_hold_hours', 72));
        $onlineHours = max(1, (int) get_option('ccr_online_hold_hours', 2));
        $now = time();
        $cancelled = 0;

        $orders = wc_get_orders([
            'status' => ['pending', 'on-hold'],
            'limit' => 50,
            'orderby' => 'date',
            'order' => 'ASC',
            'return' => 'objects',
        ]);

        foreach ($orders as $order) {
            if (! $order instanceof WC_Order || $order->is_paid()) {
                continue;
            }

            $method = (string) $order->get_meta('_ccr_payment_method');
            if ($method === '') {
                $method = (string) $order->get_payment_method();
            }

            $created = $order->get_date_created();
            if (! $created) {
                continue;
            }

            $ageHours = ($now - $created->getTimestamp()) / 3600;
            $isPickup = $method === 'ccr_pickup_cash' || $method === 'pickup_cash';
            $limit = $isPickup ? $pickupHours : $onlineHours;

            if ($ageHours < $limit) {
                continue;
            }

            $order->update_status(
                'cancelled',
                sprintf(
                    /* translators: %d: hours */
                    __('Hold expired after %d hours — unpaid reservation cancelled automatically.', 'christocentric-rentals'),
                    $limit
                )
            );
            $cancelled++;
        }

        if ($cancelled > 0) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log(sprintf('[CCR] Expired %d unpaid hold order(s).', $cancelled));
        }
    }
}
