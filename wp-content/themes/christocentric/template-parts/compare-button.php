<?php
defined('ABSPATH') || exit;

/** @var WC_Product|null $product */
$product = $args['product'] ?? null;
$compact = ! empty($args['compact']);

if (! $product instanceof WC_Product || ! class_exists('CCR_Compare')) {
    return;
}

$productId = $product->get_id();
$inCompare = CCR_Compare::has($productId);
$atLimit = CCR_Compare::count() >= CCR_Compare::MAX_ITEMS && ! $inCompare;
$label = $inCompare
    ? __('In compare', 'christocentric')
    : ($compact ? __('Compare', 'christocentric') : __('Add to compare', 'christocentric'));
?>
<button
    type="button"
    class="compare-add-btn<?php echo $inCompare ? ' is-active' : ''; ?><?php echo $compact ? ' is-compact' : ''; ?>"
    data-ccr-compare
    data-product-id="<?php echo (int) $productId; ?>"
    data-compact="<?php echo $compact ? '1' : '0'; ?>"
    data-in-compare="<?php echo $inCompare ? '1' : '0'; ?>"
    <?php disabled($atLimit); ?>
    title="<?php echo esc_attr($inCompare ? __('Remove from compare', 'christocentric') : ($atLimit ? __('Compare list is full', 'christocentric') : __('Add to compare', 'christocentric'))); ?>"
>
    <svg class="h-4 w-4" data-ccr-compare-icon fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <?php if ($inCompare) : ?>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
        <?php else : ?>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
        <?php endif; ?>
    </svg>
    <span data-ccr-compare-label><?php echo esc_html($label); ?></span>
</button>
