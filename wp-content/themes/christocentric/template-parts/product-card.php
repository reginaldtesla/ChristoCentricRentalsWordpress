<?php
defined('ABSPATH') || exit;
/** @var WC_Product|null $product */
$product = $args['product'] ?? (function_exists('wc_get_product') ? wc_get_product(get_the_ID()) : null);
if (! $product instanceof WC_Product) {
    return;
}
$url = get_permalink($product->get_id());
$name = $product->get_name();
$image = wp_get_attachment_image_url($product->get_image_id(), 'woocommerce_thumbnail') ?: wc_placeholder_img_src();
$terms = get_the_terms($product->get_id(), 'product_cat');
$category = ($terms && ! is_wp_error($terms)) ? $terms[0]->name : '';
$price = ccr_product_daily_price($product);
$is_new = ccr_product_is_new($product);
$in_stock = $product->is_in_stock();
?>
<article class="product-card group relative flex flex-col overflow-hidden transition hover:border-gray-300">
    <a href="<?php echo esc_url($url); ?>" class="product-card-media relative">
        <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($name); ?>" class="product-card-image" loading="lazy">
        <?php if ($is_new) : ?>
            <span class="absolute left-2 top-2 bg-gray-900 px-1.5 py-0.5 text-[10px] font-medium uppercase text-white">New</span>
        <?php endif; ?>
    </a>
    <div class="flex flex-1 flex-col border-t border-gray-100 p-3">
        <?php if ($category) : ?>
            <p class="mb-0.5 text-xs text-gray-500"><?php echo esc_html($category); ?></p>
        <?php endif; ?>
        <h3 class="mb-2 line-clamp-2 text-sm leading-snug text-gray-900">
            <a href="<?php echo esc_url($url); ?>" class="hover:text-primary"><?php echo esc_html($name); ?></a>
        </h3>
        <div class="mt-auto space-y-2">
            <p class="text-sm font-semibold text-gray-900">
                <?php echo esc_html(ccr_format_price($price)); ?><span class="font-normal text-gray-500">/day</span>
            </p>
            <?php if (! $in_stock) : ?>
                <span class="text-xs text-red-600">Unavailable</span>
            <?php endif; ?>
        </div>
    </div>
</article>
