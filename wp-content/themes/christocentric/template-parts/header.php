<?php
defined('ABSPATH') || exit;
$site_categories = ccr_product_categories();
$cart_count = ccr_cart_count();
$shop_url = ccr_shop_url();
$search_q = get_search_query();
if (isset($_GET['q'])) { // phpcs:ignore
    $search_q = sanitize_text_field(wp_unslash($_GET['q'])); // phpcs:ignore
}
?>
<header class="sticky top-0 z-50 border-b border-gray-200 bg-white">
    <div class="container-site">
        <div class="flex items-center gap-4 py-3 lg:py-4">
            <button type="button" class="p-1 text-gray-700 lg:hidden" data-mobile-menu-toggle aria-label="Open menu">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <a href="<?php echo esc_url(home_url('/')); ?>" class="shrink-0">
                <img src="<?php echo esc_url(ccr_theme_asset('images/brand/logo.png')); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>" class="h-12 w-auto md:h-14">
            </a>
            <form action="<?php echo esc_url($shop_url); ?>" method="get" class="hidden min-w-0 flex-1 lg:block lg:max-w-xl lg:mx-6">
                <div class="relative">
                    <input type="search" name="s" value="<?php echo esc_attr($search_q); ?>" placeholder="Search cameras, lenses, lights..." class="w-full rounded border border-gray-300 bg-white py-2.5 pl-4 pr-11 text-sm outline-none focus:border-primary focus:ring-1 focus:ring-primary">
                    <button type="submit" class="absolute right-0 top-0 flex h-full items-center px-3 text-gray-500 hover:text-primary" aria-label="Search">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z"/></svg>
                    </button>
                </div>
            </form>
            <div class="ml-auto flex items-center gap-3">
                <?php if (is_user_logged_in()) : ?>
                    <a href="<?php echo esc_url(wc_get_account_endpoint_url('orders')); ?>" class="hidden text-sm text-gray-700 hover:text-primary sm:inline">Account</a>
                <?php else : ?>
                    <a href="<?php echo esc_url(wc_get_page_permalink('myaccount')); ?>" class="hidden text-sm text-gray-700 hover:text-primary sm:inline">Sign in</a>
                <?php endif; ?>
                <a href="<?php echo esc_url(wc_get_cart_url()); ?>" class="relative flex items-center gap-1.5 text-sm text-gray-800 hover:text-primary">
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                    <span class="hidden sm:inline">Cart</span>
                    <?php if ($cart_count > 0) : ?>
                        <span class="flex h-5 min-w-5 items-center justify-center rounded-full bg-primary px-1 text-[11px] font-medium text-white"><?php echo (int) $cart_count; ?></span>
                    <?php endif; ?>
                </a>
            </div>
        </div>
        <nav class="hidden border-t border-gray-100 lg:block">
            <ul class="flex flex-wrap items-center gap-1 py-1">
                <li><a href="<?php echo esc_url(home_url('/')); ?>" class="nav-link <?php echo is_front_page() ? 'nav-link-active' : ''; ?>">Home</a></li>
                <li><a href="<?php echo esc_url($shop_url); ?>" class="nav-link <?php echo function_exists('is_shop') && is_shop() ? 'nav-link-active' : ''; ?>">All products</a></li>
                <?php foreach (array_slice($site_categories, 0, 6) as $category) : ?>
                    <li><a href="<?php echo esc_url(ccr_shop_url(['product_cat' => $category['slug']])); ?>" class="nav-link"><?php echo esc_html($category['name']); ?></a></li>
                <?php endforeach; ?>
                <li class="ml-auto"><a href="<?php echo esc_url(home_url('/help/')); ?>" class="nav-link">Help</a></li>
                <li><a href="<?php echo esc_url(home_url('/contact/')); ?>" class="nav-link">Contact</a></li>
            </ul>
        </nav>
    </div>
    <div class="hidden border-t border-gray-200 bg-white lg:hidden" data-mobile-menu>
        <div class="container-site space-y-1 py-3">
            <form action="<?php echo esc_url($shop_url); ?>" method="get" class="mb-2">
                <input type="search" name="s" placeholder="Search gear..." class="w-full rounded border border-gray-300 px-3 py-2 text-sm">
            </form>
            <a href="<?php echo esc_url(home_url('/')); ?>" class="block px-2 py-2 text-sm text-gray-800">Home</a>
            <a href="<?php echo esc_url($shop_url); ?>" class="block px-2 py-2 text-sm text-gray-800">All products</a>
            <?php foreach ($site_categories as $category) : ?>
                <a href="<?php echo esc_url(ccr_shop_url(['product_cat' => $category['slug']])); ?>" class="block px-2 py-2 text-sm text-gray-700"><?php echo esc_html($category['name']); ?></a>
            <?php endforeach; ?>
            <a href="<?php echo esc_url(home_url('/contact/')); ?>" class="block px-2 py-2 text-sm text-gray-700">Contact</a>
            <a href="<?php echo esc_url(home_url('/help/')); ?>" class="block px-2 py-2 text-sm text-gray-700">Help</a>
        </div>
    </div>
</header>
