<?php
defined('ABSPATH') || exit;

$products = class_exists('CCR_Compare') ? CCR_Compare::products() : [];
$max = class_exists('CCR_Compare') ? CCR_Compare::MAX_ITEMS : 4;
$clear_action = admin_url('admin-post.php');

get_header();
get_template_part('template-parts/page-hero', null, [
    'title' => __('Compare', 'christocentric'),
    'subtitle' => sprintf(
        /* translators: %d: max items */
        __('Up to %d products side by side', 'christocentric'),
        $max
    ),
]);
?>
<div class="container-site py-10 md:py-12" data-ccr-compare-page data-shop-url="<?php echo esc_url(ccr_shop_url()); ?>">
    <?php if ($products === []) : ?>
        <div data-ccr-compare-empty>
            <p class="text-gray-600"><?php esc_html_e('No products to compare yet.', 'christocentric'); ?></p>
            <p class="mt-2 text-sm text-gray-500">
                <?php
                printf(
                    /* translators: %d: max items */
                    esc_html__('Browse the shop and click Add to compare on up to %d items.', 'christocentric'),
                    $max
                );
                ?>
            </p>
            <a href="<?php echo esc_url(ccr_shop_url()); ?>" class="btn-solid mt-6 inline-flex"><?php esc_html_e('Browse shop', 'christocentric'); ?></a>
        </div>
    <?php else : ?>
        <div data-ccr-compare-filled>
        <form method="post" action="<?php echo esc_url($clear_action); ?>" class="mb-6" onsubmit="return confirm('<?php echo esc_js(__('Clear your compare list?', 'christocentric')); ?>');">
            <input type="hidden" name="action" value="<?php echo esc_attr(CCR_Compare::ACTION); ?>">
            <input type="hidden" name="op" value="clear">
            <?php wp_nonce_field(CCR_Compare::ACTION, 'ccr_compare_nonce'); ?>
            <button type="submit" class="text-sm font-medium text-gray-600 hover:text-primary"><?php esc_html_e('Clear compare list', 'christocentric'); ?></button>
        </form>

        <div class="ccr-compare-wrap overflow-x-auto border border-gray-200 bg-white">
            <table class="ccr-compare-table min-w-full text-sm">
                <thead>
                    <tr>
                        <th class="ccr-compare-label"></th>
                        <?php foreach ($products as $product) : ?>
                            <?php
                            $img = wp_get_attachment_image_url($product->get_image_id(), 'medium') ?: wc_placeholder_img_src();
                            ?>
                            <th class="ccr-compare-product" data-ccr-compare-col data-product-id="<?php echo (int) $product->get_id(); ?>">
                                <div class="ccr-compare-product-inner">
                                    <img src="<?php echo esc_url($img); ?>" alt="<?php echo esc_attr($product->get_name()); ?>" class="ccr-compare-image">
                                    <h2 class="ccr-compare-name"><?php echo esc_html($product->get_name()); ?></h2>
                                    <button
                                        type="button"
                                        class="ccr-compare-remove"
                                        data-ccr-compare-remove
                                        data-product-id="<?php echo (int) $product->get_id(); ?>"
                                    >
                                        <?php esc_html_e('Remove', 'christocentric'); ?>
                                    </button>
                                </div>
                            </th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <th scope="row" class="ccr-compare-label"><?php esc_html_e('Daily rate', 'christocentric'); ?></th>
                        <?php foreach ($products as $product) : ?>
                            <?php
                            $price = ccr_product_daily_price($product);
                            $regular = ccr_product_regular_daily_price($product);
                            $onSale = ccr_product_is_on_sale($product);
                            ?>
                            <td class="ccr-compare-value font-semibold text-gray-900" data-product-id="<?php echo (int) $product->get_id(); ?>">
                                <?php if ($onSale) : ?>
                                    <span class="mr-1 text-xs font-normal text-gray-400 line-through"><?php echo esc_html(ccr_format_price($regular)); ?></span>
                                <?php endif; ?>
                                <?php echo esc_html(ccr_format_price($price)); ?><span class="font-normal text-gray-500">/day</span>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row" class="ccr-compare-label"><?php esc_html_e('Category', 'christocentric'); ?></th>
                        <?php foreach ($products as $product) : ?>
                            <?php
                            $terms = get_the_terms($product->get_id(), 'product_cat');
                            $cat = ($terms && ! is_wp_error($terms)) ? $terms[0]->name : '—';
                            ?>
                            <td class="ccr-compare-value" data-product-id="<?php echo (int) $product->get_id(); ?>"><?php echo esc_html($cat); ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row" class="ccr-compare-label"><?php esc_html_e('Availability', 'christocentric'); ?></th>
                        <?php foreach ($products as $product) : ?>
                            <td class="ccr-compare-value" data-product-id="<?php echo (int) $product->get_id(); ?>">
                                <?php if ($product->is_in_stock()) : ?>
                                    <span class="text-green-700"><?php esc_html_e('In stock', 'christocentric'); ?></span>
                                    <?php if ($product->managing_stock()) : ?>
                                        <span class="text-gray-500">(<?php echo (int) $product->get_stock_quantity(); ?>)</span>
                                    <?php endif; ?>
                                <?php else : ?>
                                    <span class="text-red-600"><?php esc_html_e('Unavailable', 'christocentric'); ?></span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row" class="ccr-compare-label"><?php esc_html_e('Rating', 'christocentric'); ?></th>
                        <?php foreach ($products as $product) : ?>
                            <?php $rating = get_post_meta($product->get_id(), '_ccr_rating', true); ?>
                            <td class="ccr-compare-value" data-product-id="<?php echo (int) $product->get_id(); ?>"><?php echo $rating !== '' ? esc_html($rating . '/5') : '—'; ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row" class="ccr-compare-label"><?php esc_html_e('Highlights', 'christocentric'); ?></th>
                        <?php foreach ($products as $product) : ?>
                            <td class="ccr-compare-value" data-product-id="<?php echo (int) $product->get_id(); ?>">
                                <ul class="ccr-compare-tags">
                                    <?php if (ccr_product_is_new($product)) : ?><li><?php esc_html_e('New', 'christocentric'); ?></li><?php endif; ?>
                                    <?php if (ccr_product_is_featured($product)) : ?><li><?php esc_html_e('Featured', 'christocentric'); ?></li><?php endif; ?>
                                    <?php if (ccr_product_is_kit($product)) : ?><li><?php esc_html_e('Kit', 'christocentric'); ?></li><?php endif; ?>
                                    <?php if (ccr_product_is_on_sale($product)) : ?><li><?php esc_html_e('On sale', 'christocentric'); ?></li><?php endif; ?>
                                </ul>
                                <?php if (! ccr_product_is_new($product) && ! ccr_product_is_featured($product) && ! ccr_product_is_kit($product) && ! ccr_product_is_on_sale($product)) : ?>
                                    —
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row" class="ccr-compare-label"><?php esc_html_e('Summary', 'christocentric'); ?></th>
                        <?php foreach ($products as $product) : ?>
                            <?php
                            $summary = $product->get_short_description() ?: wp_strip_all_tags($product->get_description());
                            $summary = $summary !== '' ? wp_html_excerpt($summary, 160, '…') : '—';
                            ?>
                            <td class="ccr-compare-value text-gray-600" data-product-id="<?php echo (int) $product->get_id(); ?>"><?php echo esc_html($summary); ?></td>
                        <?php endforeach; ?>
                    </tr>
                    <tr>
                        <th scope="row" class="ccr-compare-label"></th>
                        <?php foreach ($products as $product) : ?>
                            <td class="ccr-compare-value" data-product-id="<?php echo (int) $product->get_id(); ?>">
                                <a href="<?php echo esc_url(get_permalink($product->get_id())); ?>" class="btn-solid inline-flex"><?php esc_html_e('View product', 'christocentric'); ?></a>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                </tbody>
            </table>
        </div>
        </div>
    <?php endif; ?>
</div>
<?php get_footer(); ?>
