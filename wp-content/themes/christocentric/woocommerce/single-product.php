<?php
defined('ABSPATH') || exit;
get_header();
while (have_posts()) {
    the_post();
    global $product;
    if (! $product instanceof WC_Product) {
        continue;
    }
    $terms = get_the_terms($product->get_id(), 'product_cat');
    $category = ($terms && ! is_wp_error($terms)) ? $terms[0] : null;
    $gallery_ids = [];
    $featured_id = (int) $product->get_image_id();
    if ($featured_id > 0) {
        $gallery_ids[] = $featured_id;
    }
    foreach ($product->get_gallery_image_ids() as $gid) {
        $gid = (int) $gid;
        if ($gid > 0 && ! in_array($gid, $gallery_ids, true)) {
            $gallery_ids[] = $gid;
        }
    }

    // Keep unique images only (same file uploaded twice gets different names).
    $gallery = [];
    $seenHashes = [];
    foreach ($gallery_ids as $aid) {
        $file = (string) get_attached_file($aid);
        $full = wp_get_attachment_image_url($aid, 'full')
            ?: wp_get_attachment_image_url($aid, 'large')
            ?: wp_get_attachment_image_url($aid, 'woocommerce_single');
        $thumb = wp_get_attachment_image_url($aid, 'woocommerce_gallery_thumbnail')
            ?: wp_get_attachment_image_url($aid, 'medium')
            ?: wp_get_attachment_image_url($aid, 'thumbnail')
            ?: $full;
        if (! $full) {
            continue;
        }

        $hash = is_file($file) ? (string) md5_file($file) : strtolower(basename($file !== '' ? $file : $full));
        if ($hash === '' || isset($seenHashes[$hash])) {
            continue;
        }
        $seenHashes[$hash] = true;

        $gallery[] = [
            'id' => $aid,
            'full' => $full,
            'thumb' => $thumb ?: $full,
        ];
    }
    if ($gallery === []) {
        $placeholder = wc_placeholder_img_src();
        $gallery[] = [
            'id' => 0,
            'full' => $placeholder,
            'thumb' => $placeholder,
        ];
    }
    $main = $gallery[0];
    $has_multiple = count($gallery) > 1;
    $daily = ccr_product_daily_price($product);
    $regular = ccr_product_regular_daily_price($product);
    $onSale = ccr_product_is_on_sale($product);
    $isKit = ccr_product_is_kit($product);
    $max_qty = max(1, $product->get_stock_quantity() ?: 1);
    ?>
    <div class="container-site py-6">
        <nav class="text-sm text-gray-500">
            <a href="<?php echo esc_url(home_url('/')); ?>" class="hover:text-primary">Home</a>
            <span class="mx-2">/</span>
            <a href="<?php echo esc_url(ccr_shop_url()); ?>" class="hover:text-primary">Shop</a>
            <?php if ($category) : ?>
                <span class="mx-2">/</span>
                <a href="<?php echo esc_url(get_term_link($category)); ?>" class="hover:text-primary"><?php echo esc_html($category->name); ?></a>
            <?php endif; ?>
            <span class="mx-2">/</span>
            <span class="text-gray-800"><?php the_title(); ?></span>
        </nav>
    </div>
    <div class="container-site pb-12">
        <div class="grid gap-10 lg:grid-cols-2 lg:gap-14">
            <div class="product-gallery" data-product-gallery>
                <div class="product-gallery-layout<?php echo $has_multiple ? ' product-gallery-layout--thumbs' : ''; ?>">
                    <?php if ($has_multiple) : ?>
                        <div class="product-gallery-thumbs" role="list">
                            <?php foreach ($gallery as $index => $shot) : ?>
                                <button
                                    type="button"
                                    class="product-gallery-thumb<?php echo $index === 0 ? ' is-active' : ''; ?>"
                                    data-gallery-thumb
                                    data-full="<?php echo esc_url($shot['full']); ?>"
                                    aria-label="<?php echo esc_attr(sprintf(__('View image %d', 'christocentric'), $index + 1)); ?>"
                                    aria-pressed="<?php echo $index === 0 ? 'true' : 'false'; ?>"
                                >
                                    <img src="<?php echo esc_url($shot['thumb']); ?>" alt="" loading="lazy">
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="product-gallery-main">
                        <div class="product-gallery-frame overflow-hidden rounded border border-gray-200 bg-white" data-gallery-zoom>
                            <img
                                src="<?php echo esc_url($main['full']); ?>"
                                alt="<?php the_title_attribute(); ?>"
                                class="product-gallery-image w-full"
                                data-gallery-main
                                draggable="false"
                            >
                        </div>
                        <button type="button" class="product-gallery-expand" data-gallery-expand aria-label="<?php esc_attr_e('View larger image', 'christocentric'); ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M8 3H3v5M16 3h5v5M8 21H3v-5M16 21h5v-5"/>
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="product-gallery-lightbox hidden" data-gallery-lightbox hidden>
                    <button type="button" class="product-gallery-lightbox-close" data-gallery-close aria-label="<?php esc_attr_e('Close', 'christocentric'); ?>">&times;</button>
                    <img src="<?php echo esc_url($main['full']); ?>" alt="<?php the_title_attribute(); ?>" data-gallery-lightbox-img>
                </div>
            </div>
            <div>
                <?php if ($category) : ?><p class="text-sm text-gray-500"><?php echo esc_html($category->name); ?></p><?php endif; ?>
                <h1 class="mt-1 text-2xl font-semibold text-gray-900 md:text-3xl"><?php the_title(); ?></h1>
                <p class="mt-3 text-2xl font-semibold text-gray-900">
                    <?php if ($onSale) : ?>
                        <span class="mr-2 text-lg font-normal text-gray-400 line-through"><?php echo esc_html(ccr_format_price($regular)); ?></span>
                        <span class="text-red-600"><?php echo esc_html(ccr_format_price($daily)); ?></span>
                    <?php else : ?>
                        <?php echo esc_html(ccr_format_price($daily)); ?>
                    <?php endif; ?>
                    <span class="text-base font-normal text-gray-500">/day</span>
                </p>
                <?php if ($onSale) : ?>
                    <p class="mt-1 text-sm font-medium text-red-600"><?php esc_html_e('Promo pricing — limited time', 'christocentric'); ?></p>
                <?php endif; ?>
                <?php if ($isKit) : ?>
                    <p class="mt-2 text-sm text-primary"><?php esc_html_e('Rental kit — all items below are added together.', 'christocentric'); ?></p>
                <?php endif; ?>
                <?php if ($product->is_in_stock()) : ?>
                    <p class="ccr-availability-live mt-2 text-sm text-green-700"><?php esc_html_e('Pick dates below to see how many are free', 'christocentric'); ?></p>
                <?php else : ?>
                    <p class="ccr-availability-live mt-2 text-sm text-gray-500"><?php esc_html_e('Currently unavailable', 'christocentric'); ?></p>
                <?php endif; ?>
                <div class="mt-5 border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600">
                    <p>Rental is charged per day. Choose your pickup and return dates and times below to see the total.</p>
                </div>
                <?php if ($product->is_in_stock()) : ?>
                    <div class="mt-6"><?php woocommerce_template_single_add_to_cart(); ?></div>
                <?php endif; ?>
                <div class="mt-4">
                    <?php get_template_part('template-parts/compare-button', null, ['product' => $product]); ?>
                </div>
                <?php
                if (class_exists('CCR_Product_Kits')) {
                    CCR_Product_Kits::render_kit_contents();
                }
                ?>
            </div>
        </div>
        <div class="mt-14 product-detail-tabs" data-product-tabs data-tab-style="underline">
            <div class="flex gap-1 border-b border-gray-200">
                <button type="button" data-tab-trigger="description" class="tab-trigger tab-trigger--active">Description</button>
                <button type="button" data-tab-trigger="shipping" class="tab-trigger">Delivery</button>
                <button type="button" data-tab-trigger="policy" class="tab-trigger">Rental Policy</button>
            </div>
            <div data-tab-panel="description" class="py-8">
                <div class="prose max-w-none text-gray-600"><?php the_content(); ?></div>
            </div>
            <div data-tab-panel="shipping" class="hidden py-8">
                <div class="max-w-2xl space-y-3 text-sm text-gray-600">
                    <p class="font-semibold text-gray-900">Delivery &amp; pickup</p>
                    <p>Delivery may be available for returning clients. First-time clients are required to pick up orders in person with a valid Ghana Card.</p>
                </div>
            </div>
            <div data-tab-panel="policy" class="hidden py-8">
                <ul class="max-w-2xl list-disc space-y-2 pl-5 text-sm text-gray-600">
                    <li>Account required to complete checkout.</li>
                    <li>Payment is required before your booking is confirmed.</li>
                </ul>
            </div>
        </div>
    </div>
    <?php
}
get_footer();
