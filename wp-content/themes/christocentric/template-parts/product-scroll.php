<?php
defined('ABSPATH') || exit;
/** @var WC_Product[] $products */
$products = $args['products'] ?? [];
$variant = sanitize_key((string) ($args['variant'] ?? 'default'));
if ($products === []) {
    return;
}
$isPopular = $variant === 'popular';
$root_class = 'relative min-w-0 overflow-hidden';
if ($isPopular) {
    $root_class .= ' product-scroll--popular';
}
?>
<div class="<?php echo esc_attr($root_class); ?>" data-product-scroll>
    <div class="product-scroll-track scrollbar-hide flex overflow-x-auto <?php echo $isPopular ? 'product-scroll-track--popular' : 'gap-4 pb-2'; ?>" data-product-scroll-track>
        <?php foreach ($products as $product) : ?>
            <div class="product-scroll-item shrink-0 <?php echo $isPopular ? 'product-scroll-item--popular' : ''; ?>">
                <?php get_template_part('template-parts/product-card', null, ['product' => $product, 'variant' => $variant]); ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($isPopular) : ?>
        <div class="product-scroll-progress" aria-hidden="true">
            <span class="product-scroll-progress-bar" data-product-scroll-progress></span>
        </div>
    <?php endif; ?>
    <button type="button" data-product-scroll-prev class="product-scroll-arrow product-scroll-arrow-prev <?php echo $isPopular ? 'product-scroll-arrow--popular' : ''; ?>" aria-label="Scroll left">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
    </button>
    <button type="button" data-product-scroll-next class="product-scroll-arrow product-scroll-arrow-next <?php echo $isPopular ? 'product-scroll-arrow--popular' : ''; ?>" aria-label="Scroll right">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
    </button>
</div>
