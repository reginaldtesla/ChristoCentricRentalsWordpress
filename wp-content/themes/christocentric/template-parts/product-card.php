<?php
defined('ABSPATH') || exit;
/** @var WC_Product|null $product */
$product = $args['product'] ?? (function_exists('wc_get_product') ? wc_get_product(get_the_ID()) : null);
if (! $product instanceof WC_Product) {
    return;
}
$variant = sanitize_key((string) ($args['variant'] ?? 'default'));
$isPopular = $variant === 'popular';
$url = get_permalink($product->get_id());
$name = $product->get_name();
$image = wp_get_attachment_image_url($product->get_image_id(), 'woocommerce_thumbnail')
    ?: wp_get_attachment_image_url($product->get_image_id(), 'medium')
    ?: wp_get_attachment_image_url($product->get_image_id(), 'large')
    ?: wc_placeholder_img_src();
$terms = get_the_terms($product->get_id(), 'product_cat');
$category = ($terms && ! is_wp_error($terms)) ? $terms[0]->name : '';
$price = ccr_product_daily_price($product);
$regular = ccr_product_regular_daily_price($product);
$onSale = ccr_product_is_on_sale($product);
$is_new = ccr_product_is_new($product);
$is_kit = ccr_product_is_kit($product);
$in_stock = $product->is_in_stock();
$card_class = 'product-card group relative flex flex-col overflow-hidden transition';
if ($isPopular) {
    $card_class .= ' product-card--popular';
} else {
    $card_class .= ' hover:border-gray-300';
}
?>
<article class="<?php echo esc_attr($card_class); ?>">
    <a href="<?php echo esc_url($url); ?>" class="product-card-media relative">
        <img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($name); ?>" class="product-card-image" loading="lazy">
        <?php if (! $isPopular) : ?>
            <span class="absolute left-2 top-2 flex flex-col gap-1">
                <?php if ($onSale) : ?>
                    <span class="bg-red-600 px-1.5 py-0.5 text-[10px] font-medium uppercase text-white"><?php esc_html_e('Sale', 'christocentric'); ?></span>
                <?php endif; ?>
                <?php if ($is_kit) : ?>
                    <span class="bg-primary px-1.5 py-0.5 text-[10px] font-medium uppercase text-white"><?php esc_html_e('Kit', 'christocentric'); ?></span>
                <?php endif; ?>
                <?php if ($is_new) : ?>
                    <span class="bg-gray-900 px-1.5 py-0.5 text-[10px] font-medium uppercase text-white"><?php esc_html_e('New', 'christocentric'); ?></span>
                <?php endif; ?>
            </span>
        <?php endif; ?>
    </a>
    <div class="product-card-body flex flex-1 flex-col <?php echo $isPopular ? 'product-card-body--popular' : 'border-t border-gray-100 p-3'; ?>">
        <?php if (! $isPopular && $category) : ?>
            <p class="mb-0.5 text-xs text-gray-500"><?php echo esc_html($category); ?></p>
        <?php endif; ?>
        <h3 class="product-card-title <?php echo $isPopular ? 'product-card-title--popular' : 'mb-2 line-clamp-2 text-sm leading-snug text-gray-900'; ?>">
            <a href="<?php echo esc_url($url); ?>" class="<?php echo $isPopular ? '' : 'hover:text-primary'; ?>"><?php echo esc_html($name); ?></a>
        </h3>
        <div class="product-card-meta <?php echo $isPopular ? 'product-card-meta--popular' : 'mt-auto space-y-2'; ?>">
            <p class="product-card-price <?php echo $isPopular ? 'product-card-price--popular' : 'text-sm font-semibold text-gray-900'; ?>">
                <?php if ($onSale) : ?>
                    <span class="mr-1 text-xs font-normal text-gray-400 line-through"><?php echo esc_html(ccr_format_price($regular)); ?></span>
                <?php endif; ?>
                <?php echo esc_html(ccr_format_price($price)); ?><span class="product-card-price-unit"><?php echo $isPopular ? '/Day' : '/day'; ?></span>
            </p>
            <?php if (! $isPopular) : ?>
                <div class="product-card-actions">
                    <?php if ($in_stock) : ?>
                        <button
                            type="button"
                            class="product-card-add"
                            data-ccr-quick-add
                            data-product-id="<?php echo esc_attr((string) $product->get_id()); ?>"
                        >
                            <?php esc_html_e('Add', 'christocentric'); ?>
                        </button>
                    <?php else : ?>
                        <span class="product-card-unavailable"><?php esc_html_e('Unavailable', 'christocentric'); ?></span>
                    <?php endif; ?>
                    <?php get_template_part('template-parts/compare-button', null, ['product' => $product, 'compact' => true]); ?>
                </div>
            <?php elseif (! $in_stock) : ?>
                <span class="text-xs text-red-600"><?php esc_html_e('Unavailable', 'christocentric'); ?></span>
            <?php endif; ?>
        </div>
    </div>
</article>
