<?php
/**
 * Orders list — card layout matching Laravel account/orders.blade.php.
 *
 * @see woocommerce/templates/myaccount/orders.php
 */

defined('ABSPATH') || exit;

do_action('woocommerce_before_account_orders', $has_orders);
?>

<?php if ($has_orders) : ?>
    <div class="ccr-orders-list space-y-4">
        <?php
        foreach ($customer_orders->orders as $customer_order) {
            $order = wc_get_order($customer_order); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

            if (! $order) {
                continue;
            }
            ?>
            <a href="<?php echo esc_url($order->get_view_order_url()); ?>" class="ccr-order-card block rounded-2xl border border-gray-200 bg-white p-6 transition hover:shadow-md">
                <div class="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <p class="font-semibold text-gray-900">#<?php echo esc_html($order->get_order_number()); ?></p>
                        <p class="text-sm text-gray-500"><?php echo esc_html(wc_format_datetime($order->get_date_created())); ?></p>
                    </div>
                    <div class="text-right">
                        <p class="font-bold text-primary"><?php echo wp_kses_post($order->get_formatted_order_total()); ?></p>
                        <p class="text-sm capitalize text-gray-500"><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></p>
                    </div>
                </div>
            </a>
            <?php
        }
        ?>
    </div>

    <?php do_action('woocommerce_before_account_orders_pagination'); ?>

    <?php if (1 < $customer_orders->max_num_pages) : ?>
        <nav class="ccr-pagination mt-8" aria-label="<?php esc_attr_e('Orders pagination', 'woocommerce'); ?>">
            <ul class="ccr-pagination-list">
                <?php if (1 !== $current_page) : ?>
                    <li><a class="page-numbers prev" href="<?php echo esc_url(wc_get_endpoint_url('orders', $current_page - 1)); ?>">&larr;</a></li>
                <?php endif; ?>
                <?php if ((int) $customer_orders->max_num_pages !== $current_page) : ?>
                    <li><a class="page-numbers next" href="<?php echo esc_url(wc_get_endpoint_url('orders', $current_page + 1)); ?>">&rarr;</a></li>
                <?php endif; ?>
            </ul>
        </nav>
    <?php endif; ?>
<?php else : ?>
    <div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 p-12 text-center">
        <p class="text-gray-600"><?php esc_html_e('You have no orders yet.', 'christocentric'); ?></p>
        <a href="<?php echo esc_url(ccr_shop_url()); ?>" class="btn-solid mt-6 inline-flex"><?php esc_html_e('Start Renting', 'christocentric'); ?></a>
    </div>
<?php endif; ?>

<?php do_action('woocommerce_after_account_orders', $has_orders); ?>
