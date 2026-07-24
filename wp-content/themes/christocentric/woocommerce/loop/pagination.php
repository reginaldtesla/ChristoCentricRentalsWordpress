<?php
/**
 * Product loop pagination — styled to match Christocentric design.
 *
 * @see woocommerce/templates/loop/pagination.php
 */

defined('ABSPATH') || exit;

$total   = isset($total) ? $total : wc_get_loop_prop('total_pages');
$current = isset($current) ? $current : wc_get_loop_prop('current_page');
$base    = isset($base) ? $base : esc_url_raw(str_replace(999999999, '%#%', remove_query_arg('add-to-cart', get_pagenum_link(999999999, false))));
$format  = isset($format) ? $format : '';

if ($total <= 1) {
    return;
}

$links = paginate_links(
    apply_filters(
        'woocommerce_pagination_args',
        [
            'base'      => $base,
            'format'    => $format,
            'add_args'  => false,
            'current'   => max(1, $current),
            'total'     => $total,
            'prev_text' => '&larr;',
            'next_text' => '&rarr;',
            'type'      => 'array',
            'end_size'  => 2,
            'mid_size'  => 1,
        ]
    )
);

if (empty($links) || ! is_array($links)) {
    return;
}
?>
<nav class="woocommerce-pagination ccr-pagination" aria-label="<?php esc_attr_e('Product Pagination', 'woocommerce'); ?>">
    <ul class="ccr-pagination-list">
        <?php foreach ($links as $link) : ?>
            <li><?php echo $link; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></li>
        <?php endforeach; ?>
    </ul>
</nav>
