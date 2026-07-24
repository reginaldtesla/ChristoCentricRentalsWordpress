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
    $image = wp_get_attachment_image_url($product->get_image_id(), 'large') ?: wc_placeholder_img_src();
    $daily = ccr_product_daily_price($product);
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
            <div>
                <div class="product-gallery-frame overflow-hidden rounded border border-gray-200 bg-white p-6 lg:p-8">
                    <img src="<?php echo esc_url($image); ?>" alt="<?php the_title_attribute(); ?>" class="product-gallery-image">
                </div>
            </div>
            <div>
                <?php if ($category) : ?><p class="text-sm text-gray-500"><?php echo esc_html($category->name); ?></p><?php endif; ?>
                <h1 class="mt-1 text-2xl font-semibold text-gray-900 md:text-3xl"><?php the_title(); ?></h1>
                <p class="mt-3 text-2xl font-semibold text-gray-900"><?php echo esc_html(ccr_format_price($daily)); ?><span class="text-base font-normal text-gray-500">/day</span></p>
                <?php if ($product->is_in_stock()) : ?>
                    <p class="mt-2 text-sm text-green-700">Available to rent — pick dates below</p>
                <?php else : ?>
                    <p class="mt-2 text-sm text-gray-500">Currently unavailable</p>
                <?php endif; ?>
                <div class="mt-5 border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600">
                    <p>Rental is charged per day. Choose your pickup and return dates and times below to see the total.</p>
                </div>
                <?php if ($product->is_in_stock()) : ?>
                    <div class="mt-6"><?php woocommerce_template_single_add_to_cart(); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="mt-14 product-detail-tabs" data-product-tabs data-tab-style="underline">
            <div class="flex gap-1 border-b border-gray-200">
                <button type="button" data-tab-trigger="description" class="tab-trigger tab-trigger--active">Description</button>
                <button type="button" data-tab-trigger="shipping" class="tab-trigger">Shipping &amp; Delivery</button>
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
