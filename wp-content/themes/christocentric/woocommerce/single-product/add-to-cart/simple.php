<?php
/**
 * Simple product add to cart — styled like Laravel shop/show.
 *
 * @see woocommerce/templates/single-product/add-to-cart/simple.php
 */

defined('ABSPATH') || exit;

global $product;

if (! $product->is_purchasable()) {
    return;
}

echo wc_get_stock_html($product); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

if ($product->is_in_stock()) :
    do_action('woocommerce_before_add_to_cart_form');
    ?>
    <form class="cart" action="<?php echo esc_url(apply_filters('woocommerce_add_to_cart_form_action', $product->get_permalink())); ?>" method="post" enctype="multipart/form-data">
        <?php do_action('woocommerce_before_add_to_cart_button'); ?>

        <div class="ccr-product-quantity">
            <label class="ccr-product-quantity-label" for="ccr_product_qty"><?php esc_html_e('Quantity', 'christocentric'); ?></label>
            <?php
            do_action('woocommerce_before_add_to_cart_quantity');

            woocommerce_quantity_input([
                'input_id'    => 'ccr_product_qty',
                'min_value'   => $product->get_min_purchase_quantity(),
                'max_value'   => $product->get_max_purchase_quantity(),
                'input_value' => isset($_POST['quantity']) ? wc_stock_amount(wp_unslash($_POST['quantity'])) : $product->get_min_purchase_quantity(), // phpcs:ignore
            ]);

            do_action('woocommerce_after_add_to_cart_quantity');
            ?>
        </div>

        <button
            type="submit"
            name="add-to-cart"
            value="<?php echo esc_attr($product->get_id()); ?>"
            class="single_add_to_cart_button btn-solid mt-3 w-full py-3"
        >
            <?php echo esc_html($product->single_add_to_cart_text()); ?>
        </button>

        <?php do_action('woocommerce_after_add_to_cart_button'); ?>
    </form>
    <?php
    do_action('woocommerce_after_add_to_cart_form');
endif;
