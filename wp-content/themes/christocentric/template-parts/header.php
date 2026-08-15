<?php
defined('ABSPATH') || exit;
$cart_count = ccr_cart_count();
$shop_url = ccr_shop_url();
$search_q = ccr_shop_search_query();
$nav_groups = ccr_nav_category_groups();
$categories_open = (function_exists('is_shop') && is_shop()) || is_tax('product_cat') || ccr_is_kits_view();
?>
<header class="sticky top-0 z-50 border-b border-gray-200 bg-white">
    <div class="container-site">
        <div class="flex items-center gap-4 py-3 lg:py-4">
            <button type="button" class="p-1 text-gray-700 lg:hidden" data-mobile-menu-toggle aria-expanded="false" aria-controls="ccr-mobile-menu" aria-label="<?php esc_attr_e('Open menu', 'christocentric'); ?>">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <a href="<?php echo esc_url(home_url('/')); ?>" class="shrink-0">
                <img src="<?php echo esc_url(ccr_theme_asset('images/brand/logo.png')); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>" class="h-12 w-auto md:h-14">
            </a>
            <form action="<?php echo esc_url($shop_url); ?>" method="get" class="hidden min-w-0 flex-1 lg:block lg:max-w-xl lg:mx-6" role="search">
                <div class="relative">
                    <input type="search" name="s" value="<?php echo esc_attr($search_q); ?>" placeholder="<?php esc_attr_e('Search cameras, lenses, lights...', 'christocentric'); ?>" class="w-full rounded border border-gray-300 bg-white py-2.5 pl-4 pr-11 text-sm outline-none focus:border-primary focus:ring-1 focus:ring-primary">
                    <button type="submit" class="absolute right-0 top-0 flex h-full items-center px-3 text-gray-500 hover:text-primary" aria-label="<?php esc_attr_e('Search', 'christocentric'); ?>">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"/></svg>
                    </button>
                </div>
            </form>
            <div class="ml-auto flex items-center gap-3">
                <a href="<?php echo esc_url(home_url('/compare/')); ?>" class="relative hidden items-center gap-1.5 text-sm text-gray-800 hover:text-primary sm:flex <?php echo is_page('compare') ? 'text-primary' : ''; ?>">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                    <span><?php esc_html_e('Compare', 'christocentric'); ?></span>
                    <?php $compare_count = class_exists('CCR_Compare') ? CCR_Compare::count() : 0; ?>
                    <span data-ccr-compare-count class="flex h-5 min-w-5 items-center justify-center rounded-full bg-gray-900 px-1 text-[11px] font-medium text-white" <?php echo $compare_count > 0 ? '' : 'hidden'; ?>><?php echo $compare_count > 0 ? (int) $compare_count : ''; ?></span>
                </a>
                <?php if (is_user_logged_in()) : ?>
                    <a href="<?php echo esc_url(wc_get_account_endpoint_url('orders')); ?>" class="hidden text-sm text-gray-700 hover:text-primary sm:inline"><?php esc_html_e('Account', 'christocentric'); ?></a>
                <?php else : ?>
                    <a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" class="hidden text-sm text-gray-700 hover:text-primary sm:inline"><?php esc_html_e('Sign in', 'christocentric'); ?></a>
                <?php endif; ?>
                <a href="<?php echo esc_url(wc_get_cart_url()); ?>" class="relative flex items-center gap-1.5 text-sm text-gray-800 hover:text-primary">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    <span class="hidden sm:inline"><?php esc_html_e('Cart', 'christocentric'); ?></span>
                    <span data-ccr-cart-count class="flex h-5 min-w-5 items-center justify-center rounded-full bg-primary px-1 text-[11px] font-medium text-white" <?php echo $cart_count > 0 ? '' : 'hidden'; ?>><?php echo $cart_count > 0 ? (int) $cart_count : ''; ?></span>
                </a>
            </div>
        </div>
        <nav class="hidden border-t border-gray-100 lg:block" aria-label="<?php esc_attr_e('Primary', 'christocentric'); ?>">
            <ul class="flex flex-wrap items-center gap-1 py-1">
                <li><a href="<?php echo esc_url(home_url('/')); ?>" class="nav-link <?php echo is_front_page() ? 'nav-link-active' : ''; ?>"><?php esc_html_e('Home', 'christocentric'); ?></a></li>
                <li class="ccr-nav-dropdown" data-ccr-nav-dropdown>
                    <button type="button" class="nav-link ccr-nav-dropdown-trigger <?php echo $categories_open ? 'nav-link-active' : ''; ?>" data-ccr-nav-trigger aria-expanded="false" aria-haspopup="true">
                        <?php esc_html_e('Categories', 'christocentric'); ?>
                        <svg class="ccr-nav-caret" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                    </button>
                    <div class="ccr-nav-panel" data-ccr-nav-panel hidden>
                        <div class="ccr-nav-panel-grid">
                            <?php foreach ($nav_groups as $group) : ?>
                                <div class="ccr-nav-group <?php echo ! empty($group['active']) ? 'is-active' : ''; ?>">
                                    <p class="ccr-nav-group-title"><?php echo esc_html($group['label']); ?></p>
                                    <ul class="ccr-nav-group-list">
                                        <?php foreach ($group['items'] as $item) : ?>
                                            <li>
                                                <a href="<?php echo esc_url($item['url']); ?>" class="ccr-nav-item <?php echo ! empty($item['active']) ? 'is-active' : ''; ?>">
                                                    <?php echo esc_html($item['label']); ?>
                                                </a>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </li>
                <li class="ml-auto"><a href="<?php echo esc_url(home_url('/studio/')); ?>" class="nav-link <?php echo is_page('studio') ? 'nav-link-active' : ''; ?>"><?php esc_html_e('Studio', 'christocentric'); ?></a></li>
                <li><a href="<?php echo esc_url(home_url('/help/')); ?>" class="nav-link <?php echo is_page('help') ? 'nav-link-active' : ''; ?>"><?php esc_html_e('Help', 'christocentric'); ?></a></li>
                <li><a href="<?php echo esc_url(home_url('/contact/')); ?>" class="nav-link <?php echo is_page('contact') ? 'nav-link-active' : ''; ?>"><?php esc_html_e('Contact', 'christocentric'); ?></a></li>
            </ul>
        </nav>
    </div>
    <div id="ccr-mobile-menu" class="hidden border-t border-gray-200 bg-white lg:hidden" data-mobile-menu hidden>
        <div class="container-site space-y-1 py-3">
            <form action="<?php echo esc_url($shop_url); ?>" method="get" class="mb-2" role="search">
                <input type="search" name="s" value="<?php echo esc_attr($search_q); ?>" placeholder="<?php esc_attr_e('Search gear...', 'christocentric'); ?>" class="w-full rounded border border-gray-300 px-3 py-2 text-sm">
            </form>
            <a href="<?php echo esc_url(home_url('/')); ?>" class="block px-2 py-2 text-sm <?php echo is_front_page() ? 'font-medium text-primary' : 'text-gray-800'; ?>"><?php esc_html_e('Home', 'christocentric'); ?></a>

            <div class="ccr-mobile-cats" data-ccr-mobile-cats>
                <button type="button" class="ccr-mobile-cats-trigger" data-ccr-mobile-cats-trigger aria-expanded="false">
                    <span><?php esc_html_e('Categories', 'christocentric'); ?></span>
                    <svg class="ccr-nav-caret" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                </button>
                <div class="ccr-mobile-cats-panel" data-ccr-mobile-cats-panel hidden>
                    <?php foreach ($nav_groups as $group) : ?>
                        <div class="ccr-mobile-group" data-ccr-mobile-group>
                            <button type="button" class="ccr-mobile-group-trigger" data-ccr-mobile-group-trigger aria-expanded="false">
                                <span><?php echo esc_html($group['label']); ?></span>
                                <svg class="ccr-nav-caret" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.17l3.71-3.94a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd"/></svg>
                            </button>
                            <ul class="ccr-mobile-group-list" data-ccr-mobile-group-list hidden>
                                <?php foreach ($group['items'] as $item) : ?>
                                    <li>
                                        <a href="<?php echo esc_url($item['url']); ?>" class="block px-3 py-2 text-sm <?php echo ! empty($item['active']) ? 'font-medium text-primary' : 'text-gray-700'; ?>">
                                            <?php echo esc_html($item['label']); ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <a href="<?php echo esc_url(home_url('/compare/')); ?>" class="block px-2 py-2 text-sm <?php echo is_page('compare') ? 'font-medium text-primary' : 'text-gray-800'; ?>">
                <?php esc_html_e('Compare', 'christocentric'); ?>
                <?php $compare_count_m = class_exists('CCR_Compare') ? CCR_Compare::count() : 0; ?>
                <span data-ccr-compare-count <?php echo $compare_count_m > 0 ? '' : 'hidden'; ?>><?php echo $compare_count_m > 0 ? ' (' . (int) $compare_count_m . ')' : ''; ?></span>
            </a>
            <a href="<?php echo esc_url(home_url('/studio/')); ?>" class="block px-2 py-2 text-sm <?php echo is_page('studio') ? 'font-medium text-primary' : 'text-gray-700'; ?>"><?php esc_html_e('Studio', 'christocentric'); ?></a>
            <a href="<?php echo esc_url(home_url('/about/')); ?>" class="block px-2 py-2 text-sm text-gray-700"><?php esc_html_e('About', 'christocentric'); ?></a>
            <a href="<?php echo esc_url(home_url('/faq/')); ?>" class="block px-2 py-2 text-sm text-gray-700"><?php esc_html_e('FAQ', 'christocentric'); ?></a>
            <a href="<?php echo esc_url(home_url('/contact/')); ?>" class="block px-2 py-2 text-sm <?php echo is_page('contact') ? 'font-medium text-primary' : 'text-gray-700'; ?>"><?php esc_html_e('Contact', 'christocentric'); ?></a>
            <a href="<?php echo esc_url(home_url('/help/')); ?>" class="block px-2 py-2 text-sm <?php echo is_page('help') ? 'font-medium text-primary' : 'text-gray-700'; ?>"><?php esc_html_e('Help', 'christocentric'); ?></a>
            <?php if (is_user_logged_in()) : ?>
                <a href="<?php echo esc_url(wc_get_account_endpoint_url('orders')); ?>" class="block px-2 py-2 text-sm text-gray-800"><?php esc_html_e('Account', 'christocentric'); ?></a>
            <?php else : ?>
                <a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" class="block px-2 py-2 text-sm text-gray-800"><?php esc_html_e('Sign in', 'christocentric'); ?></a>
            <?php endif; ?>
        </div>
    </div>
</header>
