<?php

defined('ABSPATH') || exit;

/**
 * Mirrors Laravel App\Services\RentalAvailability.
 */
final class CCR_Rental_Availability
{
    public static function init(): void
    {
        add_filter('woocommerce_can_reduce_order_stock', '__return_false', 20);
        add_filter('woocommerce_prevent_adjust_line_item_product_stock', '__return_true', 20);
    }

    public static function fleet_quantity(int $productId): int
    {
        $product = wc_get_product($productId);
        if (! $product instanceof WC_Product) {
            return 0;
        }
        if ($product->get_stock_status() === 'outofstock') {
            return 0;
        }

        $meta = (int) get_post_meta($productId, '_ccr_rental_quantity', true);
        if ($product->get_manage_stock()) {
            $stock = (int) $product->get_stock_quantity();
            if ($stock > 0) {
                return $stock;
            }
            if ($meta > 0) {
                return $meta;
            }

            return 1;
        }

        return $meta > 0 ? $meta : 1;
    }

    public static function available_quantity(int $productId, string $start, string $end, ?int $excludeOrderId = null): int
    {
        $stock = self::fleet_quantity($productId);
        if ($stock <= 0) {
            return 0;
        }
        $booked = self::booked_quantity($productId, $start, $end, $excludeOrderId);

        return max(0, $stock - $booked);
    }

    public static function booked_quantity(int $productId, string $start, string $end, ?int $excludeOrderId = null): int
    {
        global $wpdb;

        $startDate = gmdate('Y-m-d', strtotime($start));
        $endDate = gmdate('Y-m-d', strtotime($end));
        $pickupHoldHours = (int) get_option('ccr_pickup_cash_hold_hours', 72);
        $onlineHoldHours = (int) get_option('ccr_online_hold_hours', 2);
        $pickupCutoff = gmdate('Y-m-d H:i:s', time() - ($pickupHoldHours * HOUR_IN_SECONDS));
        $onlineCutoff = gmdate('Y-m-d H:i:s', time() - ($onlineHoldHours * HOUR_IN_SECONDS));

        $excludeSql = $excludeOrderId ? $wpdb->prepare(' AND oi.order_id != %d', $excludeOrderId) : '';
        $useHpos = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        $itemJoins = "
            FROM {$wpdb->prefix}woocommerce_order_items oi
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta pid
                ON pid.order_item_id = oi.order_item_id AND pid.meta_key = '_product_id' AND pid.meta_value = %d
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta qty
                ON qty.order_item_id = oi.order_item_id AND qty.meta_key = '_qty'
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta rs
                ON rs.order_item_id = oi.order_item_id AND rs.meta_key = '_ccr_rental_start'
            INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta re
                ON re.order_item_id = oi.order_item_id AND re.meta_key = '_ccr_rental_end'";

        if ($useHpos) {
            $ordersTable = $wpdb->prefix . 'wc_orders';
            $ordersMeta = $wpdb->prefix . 'wc_orders_meta';
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sql = $wpdb->prepare(
                "SELECT COALESCE(SUM(CAST(qty.meta_value AS UNSIGNED)), 0)
                {$itemJoins}
                INNER JOIN {$ordersTable} o ON o.id = oi.order_id AND o.type = 'shop_order'
                LEFT JOIN {$ordersMeta} pm_status ON pm_status.order_id = o.id AND pm_status.meta_key = '_ccr_payment_method'
                WHERE oi.order_item_type = 'line_item'
                  AND o.status NOT IN ('wc-cancelled', 'trash', 'auto-draft')
                  AND rs.meta_value <= %s
                  AND re.meta_value >= %s
                  {$excludeSql}
                  AND (
                    o.status IN ('wc-processing', 'wc-completed')
                    OR (
                        o.status IN ('wc-pending', 'wc-on-hold')
                        AND (
                            (pm_status.meta_value = 'pickup_cash' AND o.date_created_gmt >= %s)
                            OR ((pm_status.meta_value IS NULL OR pm_status.meta_value != 'pickup_cash') AND o.date_created_gmt >= %s)
                        )
                    )
                  )",
                $productId,
                $endDate,
                $startDate,
                $pickupCutoff,
                $onlineCutoff
            );
        } else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sql = $wpdb->prepare(
                "SELECT COALESCE(SUM(CAST(qty.meta_value AS UNSIGNED)), 0)
                {$itemJoins}
                INNER JOIN {$wpdb->posts} p ON p.ID = oi.order_id
                LEFT JOIN {$wpdb->postmeta} pm_status ON pm_status.post_id = p.ID AND pm_status.meta_key = '_ccr_payment_method'
                WHERE oi.order_item_type = 'line_item'
                  AND p.post_type = 'shop_order'
                  AND p.post_status NOT IN ('wc-cancelled', 'trash', 'auto-draft')
                  AND rs.meta_value <= %s
                  AND re.meta_value >= %s
                  {$excludeSql}
                  AND (
                    p.post_status IN ('wc-processing', 'wc-completed')
                    OR (
                        p.post_status IN ('wc-pending', 'wc-on-hold')
                        AND (
                            (pm_status.meta_value = 'pickup_cash' AND p.post_date_gmt >= %s)
                            OR ((pm_status.meta_value IS NULL OR pm_status.meta_value != 'pickup_cash') AND p.post_date_gmt >= %s)
                        )
                    )
                  )",
                $productId,
                $endDate,
                $startDate,
                $pickupCutoff,
                $onlineCutoff
            );
        }

        return (int) $wpdb->get_var($sql);
    }

    /**
     * @param array<int, array{product_id: int, rental_start: string, rental_end: string, quantity: int}> $items
     * @return list<array{product_id: int, name: string, requested: int, available: int}>
     */
    public static function validate_items(array $items, ?int $excludeOrderId = null): array
    {
        $errors = [];

        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $product = wc_get_product($productId);

            if (! $product) {
                continue;
            }

            $requested = (int) ($item['quantity'] ?? 1);
            $available = self::available_quantity(
                $productId,
                (string) ($item['rental_start'] ?? ''),
                (string) ($item['rental_end'] ?? ''),
                $excludeOrderId
            );

            if ($requested > $available) {
                $errors[] = [
                    'product_id' => $productId,
                    'name' => $product->get_name(),
                    'requested' => $requested,
                    'available' => $available,
                ];
            }
        }

        return $errors;
    }
}
