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
            <ul class="ccr-shop-cats">
                <?php foreach (ccr_nav_category_groups() as $group) : ?>
                    <?php
                    $groupOpen = ! empty($group['active']);
                    ?>
                    <li class="ccr-shop-group<?php echo $groupOpen ? ' is-active' : ''; ?><?php echo ! empty($group['link_only']) ? ' ccr-shop-group--link-only' : ''; ?>"<?php echo $groupOpen ? ' data-ccr-active-group="1"' : ''; ?>>
                        <?php if (! empty($group['link_only'])) : ?>
                            <a href="<?php echo esc_url($group['url']); ?>" class="ccr-shop-item-link<?php echo ! empty($group['active']) ? ' is-active' : ''; ?>">
                                <?php echo esc_html($group['label']); ?>
                            </a>
                        <?php else : ?>
                            <button type="button" class="ccr-shop-group-trigger" aria-expanded="false">
                                <span><?php echo esc_html($group['label']); ?></span>
                                <svg class="ccr-shop-caret" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                            </button>
                            <ul class="ccr-shop-group-panel">
                            <?php foreach ($group['items'] as $item) : ?>
                                <?php
                                $hasChildren = ! empty($item['children']) && is_array($item['children']);
                                $itemOpen = $hasChildren && ! empty($item['active']);
                                ?>
                                <li class="ccr-shop-item<?php echo $hasChildren ? ' has-children' : ''; ?><?php echo ! empty($item['active']) ? ' is-active' : ''; ?>"<?php echo $itemOpen ? ' data-ccr-active-item="1"' : ''; ?>>
                                    <?php if ($hasChildren) : ?>
                                        <div class="ccr-shop-item-row">
                                            <a href="<?php echo esc_url($item['url']); ?>" class="ccr-shop-item-link<?php echo ! empty($item['active']) ? ' is-active' : ''; ?>">
                                                <?php echo esc_html($item['label']); ?>
                                            </a>
                                            <button type="button" class="ccr-shop-item-toggle" aria-expanded="false" aria-label="<?php echo esc_attr(sprintf(__('Show subcategories of %s', 'christocentric'), $item['label'])); ?>">
                                                <svg class="ccr-shop-caret" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                                            </button>
                                        </div>
                                        <ul class="ccr-shop-item-panel">
                                            <?php foreach ($item['children'] as $child) : ?>
                                                <li>
                                                    <a href="<?php echo esc_url($child['url']); ?>" class="ccr-shop-item-link ccr-shop-item-link--child<?php echo ! empty($child['active']) ? ' is-active' : ''; ?>">
                                                        <?php echo esc_html($child['label']); ?>
                                                    </a>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php else : ?>
                                        <a href="<?php echo esc_url($item['url']); ?>" class="ccr-shop-item-link<?php echo ! empty($item['active']) ? ' is-active' : ''; ?>">
                                            <?php echo esc_html($item['label']); ?>
                                        </a>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($search !== '' || $active !== '' || $kitsView) : ?>
                <p class="mt-4">
                    <a href="<?php echo esc_url($shopUrl); ?>" class="text-sm text-primary hover:underline"><?php esc_html_e('Clear filters', 'christocentric'); ?></a>
                </p>
            <?php endif; ?>
        </aside>
        <div class="ccr-shop-main" id="ccr-shop-products">
            <?php do_action('woocommerce_before_shop_loop'); ?>
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
