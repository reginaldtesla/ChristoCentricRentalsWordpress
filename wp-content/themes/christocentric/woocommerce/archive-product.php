<?php
defined('ABSPATH') || exit;
get_header('shop');

$active = ccr_active_product_cat_slug();
$search = ccr_shop_search_query();
$shopUrl = ccr_shop_url();
$kitsView = ccr_is_kits_view();
?>

<div class="border-b border-gray-200 bg-gray-50">
    <div class="container-site py-6">
        <h1 class="text-2xl font-semibold text-gray-900">
            <?php
            if ($search !== '') {
                printf(
                    /* translators: %s: search query */
                    esc_html__('Search results for “%s”', 'christocentric'),
                    esc_html($search)
                );
            } elseif ($kitsView) {
                esc_html_e('Rental kits', 'christocentric');
            } elseif ($active !== '') {
                $term = get_term_by('slug', $active, 'product_cat');
                echo esc_html($term instanceof WP_Term ? $term->name : __('Shop', 'christocentric'));
            } else {
                esc_html_e('Shop', 'christocentric');
            }
            ?>
        </h1>
        <p class="mt-1 text-sm text-gray-600">
            <?php
            if ($search !== '') {
                printf(
                    /* translators: %d: result count */
                    esc_html(_n('%d product found', '%d products found', (int) $GLOBALS['wp_query']->found_posts, 'christocentric')),
                    (int) $GLOBALS['wp_query']->found_posts
                );
            } elseif ($kitsView) {
                esc_html_e('Bundled gear packages — one booking adds everything in the kit.', 'christocentric');
            } else {
                esc_html_e('Cameras, lenses, lighting, audio and accessories available to rent.', 'christocentric');
            }
            ?>
        </p>
        <form action="<?php echo esc_url($shopUrl); ?>" method="get" class="mt-4 max-w-xl lg:hidden">
            <div class="relative">
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search cameras, lenses, lights...', 'christocentric'); ?>" class="w-full rounded border border-gray-300 bg-white py-2.5 pl-4 pr-11 text-sm outline-none focus:border-primary focus:ring-1 focus:ring-primary">
                <?php if ($active !== '') : ?>
                    <input type="hidden" name="product_cat" value="<?php echo esc_attr($active); ?>">
                <?php endif; ?>
                <?php if ($kitsView) : ?>
                    <input type="hidden" name="ccr_kits" value="1">
                <?php endif; ?>
                <button type="submit" class="absolute right-0 top-0 flex h-full items-center px-3 text-gray-500 hover:text-primary" aria-label="<?php esc_attr_e('Search', 'christocentric'); ?>">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"/></svg>
                </button>
            </div>
        </form>
    </div>
</div>

<div class="container-site py-10">
    <div class="ccr-shop-layout">
        <aside class="ccr-shop-sidebar">
            <h2 class="mb-3 text-sm font-semibold text-gray-900"><?php esc_html_e('Categories', 'christocentric'); ?></h2>
            <ul class="ccr-shop-cats space-y-3">
                <?php foreach (ccr_nav_category_groups() as $group) : ?>
                    <li>
                        <p class="px-3 text-[11px] font-semibold uppercase tracking-wide text-gray-500"><?php echo esc_html($group['label']); ?></p>
                        <ul class="mt-1 space-y-1">
                            <?php foreach ($group['items'] as $item) : ?>
                                <li>
                                    <a href="<?php echo esc_url($item['url']); ?>" class="block rounded-lg px-3 py-2 text-sm <?php echo ! empty($item['active']) ? 'bg-primary-light font-medium text-primary' : 'text-gray-700 hover:bg-gray-100'; ?>">
                                        <?php echo esc_html($item['label']); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($search !== '' || $active !== '' || $kitsView) : ?>
                <p class="mt-4">
                    <a href="<?php echo esc_url($shopUrl); ?>" class="text-sm text-primary hover:underline"><?php esc_html_e('Clear filters', 'christocentric'); ?></a>
                </p>
            <?php endif; ?>
        </aside>
        <div class="ccr-shop-main">
            <?php if (woocommerce_product_loop()) : ?>
                <?php woocommerce_product_loop_start(); ?>
                <?php while (have_posts()) : the_post(); ?>
                    <?php wc_get_template_part('content', 'product'); ?>
                <?php endwhile; ?>
                <?php woocommerce_product_loop_end(); ?>
                <?php woocommerce_pagination(); ?>
            <?php else : ?>
                <div class="rounded border border-dashed border-gray-300 bg-gray-50 p-10 text-center">
                    <p class="text-gray-600">
                        <?php
                        echo $search !== ''
                            ? esc_html__('No products matched your search.', 'christocentric')
                            : esc_html__('Nothing in this category yet.', 'christocentric');
                        ?>
                    </p>
                    <a href="<?php echo esc_url($shopUrl); ?>" class="mt-3 inline-block text-sm text-primary hover:underline"><?php esc_html_e('Back to all products', 'christocentric'); ?></a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php get_footer('shop'); ?>
