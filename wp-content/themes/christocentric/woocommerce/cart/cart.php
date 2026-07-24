<?php
/**
 * Cart page — card layout matching Laravel design.
 *
 * @see woocommerce/templates/cart/cart.php
 */

defined('ABSPATH') || exit;

do_action('woocommerce_before_cart');
?>

<form class="woocommerce-cart-form" action="<?php echo esc_url(wc_get_cart_url()); ?>" method="post">
    <?php do_action('woocommerce_before_cart_table'); ?>

    <?php if (WC()->cart->is_empty()) : ?>
        <?php wc_get_template('cart/cart-empty.php'); ?>
    <?php else : ?>
        <div class="grid gap-8 lg:grid-cols-3">
            <div class="space-y-4 lg:col-span-2">
                <?php
                foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {
                    $_product = apply_filters('woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key);
                    $product_id = apply_filters('woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key);

                    if (! $_product || ! $_product->exists() || $cart_item['quantity'] <= 0 || ! apply_filters('woocommerce_cart_item_visible', true, $cart_item, $cart_item_key)) {
                        continue;
                    }

                    $product_permalink = apply_filters('woocommerce_cart_item_permalink', $_product->is_visible() ? $_product->get_permalink($cart_item) : '', $cart_item, $cart_item_key);
                    $thumbnail = apply_filters('woocommerce_cart_item_thumbnail', $_product->get_image('woocommerce_thumbnail', ['class' => 'h-24 w-24 shrink-0 rounded-lg object-contain']), $cart_item, $cart_item_key);
                    $days = (int) ($cart_item['ccr_rental_days'] ?? 1);
                    $daily = ccr_product_daily_price($_product);
                    ?>
                    <div class="flex flex-col gap-4 rounded-2xl border border-gray-200 bg-white p-4 sm:flex-row sm:items-center woocommerce-cart-form__cart-item <?php echo esc_attr(apply_filters('woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key)); ?>">
                        <?php echo $thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <div class="flex-1">
                            <h3 class="font-semibold text-gray-900">
                                <?php if ($product_permalink) : ?>
                                    <a href="<?php echo esc_url($product_permalink); ?>" class="hover:text-primary"><?php echo wp_kses_post(apply_filters('woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key)); ?></a>
                                <?php else : ?>
                                    <?php echo wp_kses_post(apply_filters('woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key)); ?>
                                <?php endif; ?>
                            </h3>
                            <p class="mt-1 text-sm text-gray-500">
                                <?php echo esc_html(ccr_format_price($daily)); ?>/day × <?php echo esc_html((string) $days); ?> day(s) × <?php echo esc_html((string) $cart_item['quantity']); ?>
                            </p>
                            <?php if (! empty($cart_item['ccr_rental_start'])) : ?>
                                <p class="text-sm text-gray-600">
                                    <?php echo esc_html($cart_item['ccr_rental_start'] . ' → ' . ($cart_item['ccr_rental_end'] ?? '')); ?>
                                </p>
                            <?php endif; ?>
                            <?php echo wc_get_formatted_cart_item_data($cart_item); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        </div>
                        <div class="text-right">
                            <p class="text-lg font-bold text-gray-900">
                                <?php echo apply_filters('woocommerce_cart_item_subtotal', WC()->cart->get_product_subtotal($_product, $cart_item['quantity']), $cart_item, $cart_item_key); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                            </p>
                            <a href="<?php echo esc_url(wc_get_cart_remove_url($cart_item_key)); ?>" class="mt-2 inline-block text-sm text-red-600 hover:underline" aria-label="<?php echo esc_attr(sprintf(__('Remove %s from cart', 'woocommerce'), wp_strip_all_tags($_product->get_name()))); ?>">
                                <?php esc_html_e('Remove', 'woocommerce'); ?>
                            </a>
                        </div>
                    </div>
                    <?php
                }
                ?>
            </div>

            <div class="rounded-2xl border border-gray-200 bg-gray-50 p-6 lg:sticky lg:top-28 lg:self-start">
                <h2 class="text-lg font-semibold text-gray-900"><?php esc_html_e('Order Summary', 'christocentric'); ?></h2>
                <div class="mt-4 flex justify-between text-gray-600">
                    <span><?php esc_html_e('Subtotal', 'woocommerce'); ?></span>
                    <span class="font-semibold text-gray-900"><?php wc_cart_totals_subtotal_html(); ?></span>
                </div>
                <?php foreach (WC()->cart->get_coupons() as $code => $coupon) : ?>
                    <div class="mt-2 flex justify-between text-sm text-gray-600 coupon-<?php echo esc_attr(sanitize_title($code)); ?>">
                        <span><?php wc_cart_totals_coupon_label($coupon); ?></span>
                        <span><?php wc_cart_totals_coupon_html($coupon); ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if (WC()->cart->needs_shipping() && WC()->cart->show_shipping()) : ?>
                    <?php do_action('woocommerce_cart_totals_before_shipping'); ?>
                    <?php wc_cart_totals_shipping_html(); ?>
                    <?php do_action('woocommerce_cart_totals_after_shipping'); ?>
                <?php endif; ?>
                <?php foreach (WC()->cart->get_fees() as $fee) : ?>
                    <div class="mt-2 flex justify-between text-sm text-gray-600">
                        <span><?php echo esc_html($fee->name); ?></span>
                        <span><?php wc_cart_totals_fee_html($fee); ?></span>
                    </div>
                <?php endforeach; ?>
                <?php if (wc_tax_enabled() && ! WC()->cart->display_prices_including_tax()) : ?>
                    <?php if ('itemized' === get_option('woocommerce_tax_total_display')) : ?>
                        <?php foreach (WC()->cart->get_tax_totals() as $code => $tax) : ?>
                            <div class="mt-2 flex justify-between text-sm text-gray-600">
                                <span><?php echo esc_html($tax->label); ?></span>
                                <span><?php echo wp_kses_post($tax->formatted_amount); ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php else : ?>
                        <div class="mt-2 flex justify-between text-sm text-gray-600">
                            <span><?php echo esc_html(WC()->countries->tax_or_vat()); ?></span>
                            <span><?php wc_cart_totals_taxes_total_html(); ?></span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
                <div class="mt-4 flex justify-between border-t border-gray-200 pt-4 text-base font-semibold text-gray-900 order-total">
                    <span><?php esc_html_e('Total', 'woocommerce'); ?></span>
                    <span><?php wc_cart_totals_order_total_html(); ?></span>
                </div>
                <p class="mt-2 text-xs text-gray-500"><?php esc_html_e('All bookings must be paid in full before confirmation.', 'christocentric'); ?></p>
                <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" class="btn-solid mt-6 block w-full py-3 text-center"><?php esc_html_e('Proceed to Checkout', 'christocentric'); ?></a>
                <a href="<?php echo esc_url(ccr_shop_url()); ?>" class="mt-3 block text-center text-sm text-primary hover:underline"><?php esc_html_e('Continue shopping', 'christocentric'); ?></a>
            </div>
        </div>

        <?php wp_nonce_field('woocommerce-cart', 'woocommerce-cart-nonce'); ?>
    <?php endif; ?>

    <?php do_action('woocommerce_after_cart_table'); ?>
</form>

<?php do_action('woocommerce_before_cart_collaterals'); ?>
<div class="cart-collaterals hidden"><?php do_action('woocommerce_cart_collaterals'); ?></div>
<?php do_action('woocommerce_after_cart'); ?>
