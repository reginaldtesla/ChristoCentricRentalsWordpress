<?php

defined('ABSPATH') || exit;

/**
 * Admin booking ops: rental columns, order meta box, mark returned + penalties.
 */
final class CCR_Order_Admin
{
    public static function init(): void
    {
        add_filter('manage_edit-shop_order_columns', [self::class, 'add_columns'], 20);
        add_filter('manage_woocommerce_page_wc-orders_columns', [self::class, 'add_columns'], 20);
        add_action('manage_shop_order_posts_custom_column', [self::class, 'render_column'], 20, 2);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [self::class, 'render_hpos_column'], 20, 2);

        add_action('add_meta_boxes', [self::class, 'add_metabox']);
        add_action('woocommerce_admin_order_data_after_billing_address', [self::class, 'render_ops_hint']);
        add_action('admin_post_ccr_mark_returned', [self::class, 'handle_mark_returned']);
    }

    public static function add_columns(array $columns): array
    {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'order_status') {
                $new['ccr_rental'] = __('Rental dates', 'christocentric-rentals');
                $new['ccr_payment'] = __('Pay method', 'christocentric-rentals');
            }
        }

        return $new;
    }

    public static function render_column(string $column, int $postId): void
    {
        $order = wc_get_order($postId);
        if ($order) {
            self::output_column($column, $order);
        }
    }

    public static function render_hpos_column(string $column, $order): void
    {
        if ($order instanceof WC_Order) {
            self::output_column($column, $order);
        }
    }

    private static function output_column(string $column, WC_Order $order): void
    {
        if ($column === 'ccr_rental') {
            $ranges = self::rental_ranges($order);
            if ($ranges === []) {
                echo '&mdash;';
                return;
            }
            echo '<small>' . esc_html(implode(' · ', $ranges)) . '</small>';
            $penalty = (float) $order->get_meta('_ccr_total_late_penalty');
            if ($penalty > 0) {
                echo '<br><small style="color:#b91c1c">' . esc_html(sprintf(
                    /* translators: %s: money */
                    __('Penalty %s', 'christocentric-rentals'),
                    wp_strip_all_tags(wc_price($penalty))
                )) . '</small>';
            }
            return;
        }

        if ($column === 'ccr_payment') {
            $method = $order->get_payment_method_title() ?: $order->get_payment_method();
            echo esc_html($method !== '' ? $method : '—');
            if ($order->get_payment_method() === 'ccr_pickup_cash' && ! $order->is_paid()) {
                echo '<br><span style="color:#b45309">' . esc_html__('Awaiting cash', 'christocentric-rentals') . '</span>';
            }
        }
    }

    public static function add_metabox(): void
    {
        $screens = ['shop_order', 'woocommerce_page_wc-orders'];
        foreach ($screens as $screen) {
            add_meta_box(
                'ccr_rental_ops',
                __('Rental booking', 'christocentric-rentals'),
                [self::class, 'render_metabox'],
                $screen,
                'side',
                'high'
            );
        }
    }

    public static function render_metabox($postOrOrder): void
    {
        $order = $postOrOrder instanceof WC_Order ? $postOrOrder : wc_get_order($postOrOrder->ID ?? 0);
        if (! $order instanceof WC_Order) {
            echo '<p>' . esc_html__('Order not found.', 'christocentric-rentals') . '</p>';
            return;
        }

        echo '<ol style="margin:0 0 12px 1.1em;padding:0;font-size:12px;line-height:1.45">';
        echo '<li>' . esc_html__('Confirm dates & stock below.', 'christocentric-rentals') . '</li>';
        if ($order->get_payment_method() === 'ccr_pickup_cash') {
            echo '<li>' . esc_html__('Collect cash at pickup, then use Order actions → Mark paid at pickup.', 'christocentric-rentals') . '</li>';
        } else {
            echo '<li>' . esc_html__('Online payment: wait for Paystack → Processing.', 'christocentric-rentals') . '</li>';
        }
        echo '<li>' . esc_html__('Hand out gear on pickup day.', 'christocentric-rentals') . '</li>';
        echo '<li>' . esc_html__('Mark each item returned below (calculates late penalties).', 'christocentric-rentals') . '</li>';
        echo '</ol>';

        echo '<table class="widefat striped" style="font-size:12px"><thead><tr>';
        echo '<th>' . esc_html__('Item', 'christocentric-rentals') . '</th>';
        echo '<th>' . esc_html__('Return', 'christocentric-rentals') . '</th>';
        echo '<th>' . esc_html__('Status', 'christocentric-rentals') . '</th>';
        echo '</tr></thead><tbody>';

        $hasRows = false;
        $totalPenalty = 0.0;
        foreach ($order->get_items() as $itemId => $item) {
            if (! $item instanceof WC_Order_Item_Product) {
                continue;
            }
            $start = (string) $item->get_meta('_ccr_rental_start');
            $end = (string) $item->get_meta('_ccr_rental_end');
            if ($start === '' && $end === '') {
                continue;
            }
            $hasRows = true;
            $returnTime = (string) $item->get_meta('_ccr_return_time');
            $snap = class_exists('CCR_Rental_Due') ? CCR_Rental_Due::snapshot($item) : null;
            $totalPenalty += (float) ($snap['late_penalty'] ?? 0);

            echo '<tr>';
            echo '<td>' . esc_html($item->get_name()) . '<br><small>' . esc_html(self::format_dt($start, (string) $item->get_meta('_ccr_pickup_time'))) . '</small></td>';
            echo '<td>' . esc_html(self::format_dt($end, $returnTime)) . '</td>';
            echo '<td>';
            if ($snap) {
                echo esc_html($snap['label']);
                if (! $snap['is_returned']) {
                    $url = wp_nonce_url(
                        admin_url('admin-post.php?action=ccr_mark_returned&order_id=' . $order->get_id() . '&item_id=' . (int) $itemId),
                        'ccr_mark_returned_' . $order->get_id() . '_' . (int) $itemId
                    );
                    echo '<br><a class="button button-small" style="margin-top:4px" href="' . esc_url($url) . '">' . esc_html__('Mark returned', 'christocentric-rentals') . '</a>';
                } elseif (($snap['late_penalty'] ?? 0) > 0) {
                    echo '<br><strong style="color:#b91c1c">' . esc_html(wp_strip_all_tags(wc_price((float) $snap['late_penalty']))) . '</strong>';
                }
            } else {
                echo '—';
            }
            echo '</td>';
            echo '</tr>';
        }

        if (! $hasRows) {
            echo '<tr><td colspan="3">' . esc_html__('No rental dates on this order.', 'christocentric-rentals') . '</td></tr>';
        }
        echo '</tbody></table>';

        if ($totalPenalty > 0) {
            echo '<p style="margin:10px 0 0;font-size:12px"><strong>' . esc_html__('Late penalties total:', 'christocentric-rentals') . '</strong> ';
            echo esc_html(wp_strip_all_tags(wc_price($totalPenalty))) . '</p>';
        }
    }

    public static function handle_mark_returned(): void
    {
        if (! current_user_can('edit_shop_orders')) {
            wp_die(esc_html__('Forbidden.', 'christocentric-rentals'));
        }

        $orderId = absint($_GET['order_id'] ?? 0);
        $itemId = absint($_GET['item_id'] ?? 0);
        check_admin_referer('ccr_mark_returned_' . $orderId . '_' . $itemId);

        $order = wc_get_order($orderId);
        if (! $order instanceof WC_Order) {
            wp_die(esc_html__('Order not found.', 'christocentric-rentals'));
        }

        $item = $order->get_item($itemId);
        if (! $item instanceof WC_Order_Item_Product) {
            wp_die(esc_html__('Order item not found.', 'christocentric-rentals'));
        }

        if (class_exists('CCR_Rental_Due')) {
            CCR_Rental_Due::mark_returned($item, $order);
        }

        $redirect = $order->get_edit_order_url();
        wp_safe_redirect($redirect);
        exit;
    }

    public static function render_ops_hint(WC_Order $order): void
    {
        if ($order->get_payment_method() !== 'ccr_pickup_cash' || $order->is_paid()) {
            return;
        }
        echo '<p style="margin-top:12px;padding:8px 10px;background:#fff7ed;border-left:3px solid #f59e0b">';
        echo esc_html__('Pay on pickup: stock is held. After collecting cash, use Order actions → Mark paid at pickup.', 'christocentric-rentals');
        echo '</p>';
    }

    /**
     * @return list<string>
     */
    private static function rental_ranges(WC_Order $order): array
    {
        $out = [];
        foreach ($order->get_items() as $item) {
            if (! $item instanceof WC_Order_Item_Product) {
                continue;
            }
            $start = (string) $item->get_meta('_ccr_rental_start');
            $end = (string) $item->get_meta('_ccr_rental_end');
            if ($start === '') {
                continue;
            }
            $out[] = $start === $end ? $start : ($start . ' → ' . $end);
        }

        return array_values(array_unique($out));
    }

    private static function format_dt(string $date, string $time): string
    {
        if ($date === '') {
            return '—';
        }
        if ($time === '') {
            return $date;
        }

        return $date . ' ' . $time;
    }
}
