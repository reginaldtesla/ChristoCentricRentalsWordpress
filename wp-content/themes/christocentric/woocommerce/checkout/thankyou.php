<?php
/**
 * Order confirmation (thank you) — shown after Paystack, pay-on-pickup, or any gateway.
 *
 * @see woocommerce/templates/checkout/thankyou.php
 * @var WC_Order $order
 */

defined('ABSPATH') || exit;

remove_action('woocommerce_thankyou', 'woocommerce_order_details_table', 10);

$pickupAddress = function_exists('ccr_site_config')
    ? trim((string) ccr_site_config('contact.address', 'Bomso, near Abesse Gaming Center') . ', ' . (string) ccr_site_config('contact.city', 'Kumasi'))
    : 'Bomso, Kumasi';
$pickupPhone = function_exists('ccr_site_config') ? (string) ccr_site_config('contact.phone_display', '') : '';
$supportEmail = function_exists('ccr_site_config') ? (string) ccr_site_config('contact.support_email', get_option('admin_email')) : (string) get_option('admin_email');

$rentalStart = '';
$rentalEnd = '';
$pickupTime = '';
$returnTime = '';
if ($order instanceof WC_Order) {
    foreach ($order->get_items() as $item) {
        $start = (string) $item->get_meta('_ccr_rental_start');
        $end = (string) $item->get_meta('_ccr_rental_end');
        if ($start !== '' && ($rentalStart === '' || $start < $rentalStart)) {
            $rentalStart = $start;
            $pickupTime = (string) $item->get_meta('_ccr_pickup_time');
        }
        if ($end !== '' && ($rentalEnd === '' || $end > $rentalEnd)) {
            $rentalEnd = $end;
            $returnTime = (string) $item->get_meta('_ccr_return_time');
        }
    }
}

$formatDay = static function (string $date, string $time): string {
    if ($date === '') {
        return '';
    }
    $ts = strtotime(trim($date . ' ' . $time));
    if (! $ts) {
        $ts = strtotime($date);
    }

    return $ts ? wp_date('l, j F Y \a\t g:i a', $ts) : $date;
};

$isPickupCash = $order instanceof WC_Order && $order->get_payment_method() === 'ccr_pickup_cash';
$isPaid = $order instanceof WC_Order && ($order->is_paid() || $order->has_status(['processing', 'completed']));
?>

<div class="woocommerce-order ccr-confirm">

<?php if ($order) : ?>
    <?php do_action('woocommerce_before_thankyou', $order->get_id()); ?>

    <?php if ($order->has_status('failed')) : ?>
        <div class="ccr-confirm-hero ccr-confirm-hero--error">
            <h1><?php esc_html_e('Payment was not completed', 'christocentric'); ?></h1>
            <p><?php esc_html_e('Your bank or payment provider declined this transaction. You can try again — your cart items are still on the order.', 'christocentric'); ?></p>
            <p class="ccr-confirm-actions">
                <a class="button" href="<?php echo esc_url($order->get_checkout_payment_url()); ?>"><?php esc_html_e('Try payment again', 'christocentric'); ?></a>
                <?php if (is_user_logged_in()) : ?>
                    <a class="ccr-confirm-link" href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>"><?php esc_html_e('My account', 'christocentric'); ?></a>
                <?php endif; ?>
            </p>
        </div>
    <?php else : ?>
        <div class="ccr-confirm-hero">
            <p class="ccr-confirm-kicker"><?php esc_html_e('Order confirmed', 'christocentric'); ?></p>
            <h1>
                <?php
                echo $isPickupCash
                    ? esc_html__('Thank you for your booking', 'christocentric')
                    : esc_html__('Thank you for your order', 'christocentric');
                ?>
            </h1>
            <p class="ccr-confirm-lead">
                <?php
                if ($isPickupCash) {
                    esc_html_e('Your gear is reserved. Pay in cash when you collect it at our Bomso office, and bring a valid Ghana Card.', 'christocentric');
                } elseif ($isPaid) {
                    esc_html_e('Payment received. A receipt is on its way to your email with this same summary.', 'christocentric');
                } else {
                    esc_html_e('We have your booking. If you paid online, confirmation can take a moment — we will email you as soon as payment lands.', 'christocentric');
                }
                ?>
            </p>
        </div>

        <dl class="ccr-confirm-meta">
            <div>
                <dt><?php esc_html_e('Order number', 'christocentric'); ?></dt>
                <dd>#<?php echo esc_html($order->get_order_number()); ?></dd>
            </div>
            <div>
                <dt><?php esc_html_e('Date', 'christocentric'); ?></dt>
                <dd><?php echo esc_html(wc_format_datetime($order->get_date_created())); ?></dd>
            </div>
            <?php if ($order->get_billing_email()) : ?>
                <div>
                    <dt><?php esc_html_e('Email', 'christocentric'); ?></dt>
                    <dd><?php echo esc_html($order->get_billing_email()); ?></dd>
                </div>
            <?php endif; ?>
            <div>
                <dt><?php esc_html_e('Payment', 'christocentric'); ?></dt>
                <dd><?php echo wp_kses_post($order->get_payment_method_title() ?: '—'); ?></dd>
            </div>
            <div>
                <dt><?php esc_html_e('Total', 'christocentric'); ?></dt>
                <dd><?php echo wp_kses_post($order->get_formatted_order_total()); ?></dd>
            </div>
        </dl>

        <div class="ccr-confirm-grid">
            <section class="ccr-confirm-card">
                <h2><?php esc_html_e('Items booked', 'christocentric'); ?></h2>
                <ul class="ccr-confirm-items">
                    <?php foreach ($order->get_items() as $item) : ?>
                        <?php
                        $product = $item->get_product();
                        $start = (string) $item->get_meta('_ccr_rental_start');
                        $end = (string) $item->get_meta('_ccr_rental_end');
                        $days = (int) $item->get_meta('_ccr_rental_days');
                        ?>
                        <li>
                            <div class="ccr-confirm-item-main">
                                <strong><?php echo esc_html($item->get_name()); ?></strong>
                                <span>× <?php echo esc_html((string) $item->get_quantity()); ?></span>
                                <?php if ($start !== '' && $end !== '') : ?>
                                    <small>
                                        <?php
                                        echo esc_html($start . ' → ' . $end);
                                        if ($days > 0) {
                                            echo ' · ' . esc_html(sprintf(_n('%d day', '%d days', $days, 'christocentric'), $days));
                                        }
                                        ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                            <div class="ccr-confirm-item-price"><?php echo wp_kses_post($order->get_formatted_line_subtotal($item)); ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>

                <table class="ccr-confirm-totals">
                    <tr>
                        <th><?php esc_html_e('Subtotal', 'christocentric'); ?></th>
                        <td><?php echo wp_kses_post($order->get_subtotal_to_display()); ?></td>
                    </tr>
                    <?php foreach ($order->get_tax_totals() as $code => $tax) : ?>
                        <tr>
                            <th><?php echo esc_html($tax->label); ?></th>
                            <td><?php echo wp_kses_post($tax->formatted_amount); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ((float) $order->get_shipping_total() > 0) : ?>
                        <tr>
                            <th><?php esc_html_e('Delivery', 'christocentric'); ?></th>
                            <td><?php echo wp_kses_post(wc_price($order->get_shipping_total(), ['currency' => $order->get_currency()])); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr class="is-total">
                        <th><?php echo $isPickupCash || ! $isPaid ? esc_html__('Order total', 'christocentric') : esc_html__('Total paid', 'christocentric'); ?></th>
                        <td><?php echo wp_kses_post($order->get_formatted_order_total()); ?></td>
                    </tr>
                </table>
            </section>

            <section class="ccr-confirm-card">
                <h2><?php esc_html_e('Pickup & fulfillment', 'christocentric'); ?></h2>
                <p class="ccr-confirm-label"><?php esc_html_e('Collection point', 'christocentric'); ?></p>
                <p><?php echo esc_html($pickupAddress); ?></p>
                <?php if ($pickupPhone !== '') : ?>
                    <p><a href="tel:<?php echo esc_attr(preg_replace('/\s+/', '', $pickupPhone)); ?>"><?php echo esc_html($pickupPhone); ?></a></p>
                <?php endif; ?>

                <?php if ($rentalStart !== '') : ?>
                    <p class="ccr-confirm-label"><?php esc_html_e('Pickup', 'christocentric'); ?></p>
                    <p><?php echo esc_html($formatDay($rentalStart, $pickupTime ?: '09:00')); ?></p>
                <?php endif; ?>
                <?php if ($rentalEnd !== '') : ?>
                    <p class="ccr-confirm-label"><?php esc_html_e('Return', 'christocentric'); ?></p>
                    <p><?php echo esc_html($formatDay($rentalEnd, $returnTime ?: '17:00')); ?></p>
                <?php endif; ?>

                <p class="ccr-confirm-label"><?php esc_html_e('First-time pickup', 'christocentric'); ?></p>
                <p><?php esc_html_e('Bring a valid Ghana Card. The account holder should be present.', 'christocentric'); ?></p>
            </section>
        </div>

        <section class="ccr-confirm-next">
            <h2><?php esc_html_e('What happens next', 'christocentric'); ?></h2>
            <ol>
                <li><?php esc_html_e('A confirmation email with this receipt is being sent to your inbox (check spam if you do not see it).', 'christocentric'); ?></li>
                <li><?php echo $isPickupCash
                    ? esc_html__('Your reservation is held. Pay cash at the office when you collect the gear.', 'christocentric')
                    : esc_html__('Payment is captured by Paystack. Our team is notified to prepare your booking.', 'christocentric'); ?></li>
                <li><?php esc_html_e('Stock for these dates is reserved so the same unit is not double-booked.', 'christocentric'); ?></li>
                <li><?php esc_html_e('Collect on your pickup date, then return on time to avoid late fees.', 'christocentric'); ?></li>
            </ol>
            <p class="ccr-confirm-actions">
                <a class="button" href="<?php echo esc_url(wc_get_account_endpoint_url('orders')); ?>"><?php esc_html_e('View my orders', 'christocentric'); ?></a>
                <a class="ccr-confirm-link" href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>"><?php esc_html_e('Continue shopping', 'christocentric'); ?></a>
            </p>
            <p class="ccr-confirm-help">
                <?php
                printf(
                    /* translators: %s: support email */
                    esc_html__('Questions? Email %s', 'christocentric'),
                    '<a href="mailto:' . esc_attr($supportEmail) . '">' . esc_html($supportEmail) . '</a>'
                );
                ?>
            </p>
        </section>
    <?php endif; ?>

    <?php do_action('woocommerce_thankyou_' . $order->get_payment_method(), $order->get_id()); ?>
    <?php do_action('woocommerce_thankyou', $order->get_id()); ?>

<?php else : ?>
    <div class="ccr-confirm-hero">
        <h1><?php esc_html_e('Thank you', 'christocentric'); ?></h1>
        <p><?php esc_html_e('Your order has been received. Sign in to your account to see the full receipt.', 'christocentric'); ?></p>
        <p class="ccr-confirm-actions">
            <a class="button" href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>"><?php esc_html_e('Go to my account', 'christocentric'); ?></a>
        </p>
    </div>
<?php endif; ?>

</div>
