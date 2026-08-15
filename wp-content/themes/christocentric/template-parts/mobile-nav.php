<?php defined('ABSPATH') || exit; $cart_count = ccr_cart_count(); ?>
<nav class="fixed inset-x-0 bottom-0 z-50 border-t border-gray-200 bg-white md:hidden">
    <div class="grid grid-cols-5 text-center text-[11px]">
        <a href="<?php echo esc_url(home_url('/')); ?>" class="flex flex-col items-center gap-1 py-3 <?php echo is_front_page() ? 'text-primary' : 'text-gray-600'; ?>">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
            Home
        </a>
        <a href="<?php echo esc_url(ccr_shop_url()); ?>" class="flex flex-col items-center gap-1 py-3 <?php echo function_exists('is_shop') && is_shop() ? 'text-primary' : 'text-gray-600'; ?>">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z"/></svg>
            Shop
        </a>
        <a href="<?php echo esc_url(home_url('/help/')); ?>" class="flex flex-col items-center gap-1 py-3 <?php echo is_page('help') ? 'text-primary' : 'text-gray-600'; ?>">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 1.956-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Help
        </a>
        <a href="<?php echo esc_url(wc_get_cart_url()); ?>" class="relative flex flex-col items-center gap-1 py-3 <?php echo function_exists('is_cart') && is_cart() ? 'text-primary' : 'text-gray-600'; ?>">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
            Cart
            <span data-ccr-cart-count class="absolute right-6 top-2 flex h-4 w-4 items-center justify-center rounded-full bg-primary text-[10px] font-bold text-white" <?php echo $cart_count > 0 ? '' : 'hidden'; ?>><?php echo $cart_count > 0 ? (int) $cart_count : ''; ?></span>
        </a>
        <a href="<?php echo esc_url(is_user_logged_in() ? wc_get_account_endpoint_url('orders') : wc_get_page_permalink('myaccount')); ?>" class="flex flex-col items-center gap-1 py-3 text-gray-600">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            Account
        </a>
    </div>
</nav>
