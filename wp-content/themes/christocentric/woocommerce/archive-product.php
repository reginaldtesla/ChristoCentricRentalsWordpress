<?php
defined('ABSPATH') || exit;
get_header('shop');

$categories = ccr_product_categories();
$active = get_query_var('product_cat');
if (is_string($active) && str_contains($active, '/')) {
    $active = basename($active);
}
?>

<div class="border-b border-gray-200 bg-gray-50">
    <div class="container-site py-6">
        <h1 class="text-2xl font-semibold text-gray-900">Shop</h1>
        <p class="mt-1 text-sm text-gray-600">Cameras, lenses, lighting, audio and accessories available to rent.</p>
    </div>
</div>

<div class="container-site py-10">
    <div class="flex flex-col gap-8 lg:flex-row">
        <aside class="lg:w-56 shrink-0">
            <h2 class="mb-3 text-sm font-semibold text-gray-900">Categories</h2>
            <ul class="space-y-1">
                <li><a href="<?php echo esc_url(ccr_shop_url()); ?>" class="block rounded-lg px-3 py-2 text-sm <?php echo empty($active) ? 'bg-primary-light font-medium text-primary' : 'text-gray-700 hover:bg-gray-100'; ?>">All Products</a></li>
                <?php foreach ($categories as $category) : ?>
                    <li><a href="<?php echo esc_url(get_term_link($category['slug'], 'product_cat')); ?>" class="block rounded-lg px-3 py-2 text-sm <?php echo $active === $category['slug'] ? 'bg-primary-light font-medium text-primary' : 'text-gray-700 hover:bg-gray-100'; ?>"><?php echo esc_html($category['name']); ?></a></li>
                <?php endforeach; ?>
            </ul>
        </aside>
        <div class="flex-1">
            <?php if (woocommerce_product_loop()) : ?>
                <?php woocommerce_product_loop_start(); ?>
                <?php while (have_posts()) : the_post(); ?>
                    <?php wc_get_template_part('content', 'product'); ?>
                <?php endwhile; ?>
                <?php woocommerce_product_loop_end(); ?>
                <?php woocommerce_pagination(); ?>
            <?php else : ?>
                <div class="rounded border border-dashed border-gray-300 bg-gray-50 p-10 text-center">
                    <p class="text-gray-600">Nothing in this category yet.</p>
                    <a href="<?php echo esc_url(ccr_shop_url()); ?>" class="mt-3 inline-block text-sm text-primary hover:underline">Back to all products</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php get_footer('shop'); ?>
