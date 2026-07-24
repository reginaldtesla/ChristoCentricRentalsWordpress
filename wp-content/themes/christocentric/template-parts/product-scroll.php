<?php
defined('ABSPATH') || exit;
/** @var WC_Product[] $products */
$products = $args['products'] ?? [];
if ($products === []) {
    return;
}
?>
<div class="relative min-w-0 overflow-hidden" data-product-scroll>
    <div class="product-scroll-track scrollbar-hide flex gap-4 overflow-x-auto pb-2" data-product-scroll-track>
        <?php foreach ($products as $product) : ?>
            <div class="product-scroll-item shrink-0">
                <?php get_template_part('template-parts/product-card', null, ['product' => $product]); ?>
            </div>
        <?php endforeach; ?>
    </div>
    <button type="button" data-product-scroll-prev class="product-scroll-arrow product-scroll-arrow-prev" aria-label="Scroll left">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    </button>
    <button type="button" data-product-scroll-next class="product-scroll-arrow product-scroll-arrow-next" aria-label="Scroll right">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
    </button>
</div>
