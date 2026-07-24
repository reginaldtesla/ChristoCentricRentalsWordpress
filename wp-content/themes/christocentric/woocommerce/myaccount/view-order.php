<?php
/**
 * Single order view — styled summary matching Laravel account/order-show.
 *
 * @see woocommerce/templates/myaccount/view-order.php
 */

defined('ABSPATH') || exit;

$notes = $order->get_customer_order_notes();
$payment_method = $order->get_payment_method_title();
?>
<div class="grid gap-8 lg:grid-cols-3">
    <div class="space-y-4 lg:col-span-2">
        <div class="rounded-2xl border border-gray-200 bg-white p-6">
            <p class="text-sm text-gray-600">
                <?php
                printf(
                    esc_html__('Order #%1$s was placed on %2$s and is currently %3$s.', 'woocommerce'),
                    esc_html($order->get_order_number()),
                    esc_html(wc_format_datetime($order->get_date_created())),
                    esc_html(wc_get_order_status_name($order->get_status()))
                );
                ?>
            </p>
        </div>

        <?php do_action('woocommerce_view_order', $order_id); ?>

        <?php if ($notes) : ?>
            <div class="rounded-2xl border border-gray-200 bg-white p-6">
                <h2 class="mb-4 text-lg font-semibold text-gray-900"><?php esc_html_e('Order updates', 'woocommerce'); ?></h2>
                <ol class="ccr-order-notes space-y-4">
                    <?php foreach ($notes as $note) : ?>
                        <li class="border-b border-gray-100 pb-4 last:border-0 last:pb-0">
                            <p class="text-xs text-gray-500"><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($note->comment_date))); ?></p>
                            <div class="mt-1 text-sm text-gray-700"><?php echo wp_kses_post(wpautop(wptexturize($note->comment_content))); ?></div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        <?php endif; ?>
    </div>

    <aside class="rounded-2xl border border-gray-200 bg-gray-50 p-6 lg:sticky lg:top-28 lg:self-start">
        <h2 class="font-semibold text-gray-900"><?php esc_html_e('Summary', 'christocentric'); ?></h2>
        <dl class="mt-4 space-y-2 text-sm">
            <div class="flex justify-between gap-4">
                <dt class="text-gray-500"><?php esc_html_e('Status', 'woocommerce'); ?></dt>
                <dd class="capitalize text-gray-900"><?php echo esc_html(wc_get_order_status_name($order->get_status())); ?></dd>
            </div>
            <?php if ($payment_method) : ?>
                <div class="flex justify-between gap-4">
                    <dt class="text-gray-500"><?php esc_html_e('Payment method', 'woocommerce'); ?></dt>
                    <dd class="text-gray-900"><?php echo esc_html($payment_method); ?></dd>
                </div>
            <?php endif; ?>
            <div class="flex justify-between gap-4">
                <dt class="text-gray-500"><?php esc_html_e('Total', 'woocommerce'); ?></dt>
                <dd class="font-bold text-primary"><?php echo wp_kses_post($order->get_formatted_order_total()); ?></dd>
            </div>
        </dl>
        <a href="<?php echo esc_url(wc_get_account_endpoint_url('orders')); ?>" class="mt-6 inline-block text-sm text-primary hover:underline">&larr; <?php esc_html_e('Back to orders', 'christocentric'); ?></a>
    </aside>
</div>
