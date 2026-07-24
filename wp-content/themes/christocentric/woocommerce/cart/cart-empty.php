<?php
defined('ABSPATH') || exit;
?>
<div class="rounded-2xl border border-dashed border-gray-300 bg-gray-50 p-12 text-center">
    <p class="text-gray-600"><?php esc_html_e('Your cart is empty.', 'woocommerce'); ?></p>
    <?php if (wc_get_page_id('shop') > 0) : ?>
        <a class="btn-solid mt-6 inline-flex" href="<?php echo esc_url(apply_filters('woocommerce_return_to_shop_redirect', wc_get_page_permalink('shop'))); ?>">
            <?php esc_html_e('Browse Gear', 'christocentric'); ?>
        </a>
    <?php endif; ?>
</div>
